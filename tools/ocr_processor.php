<?php
/**
 * OCR Processor - Extract time data from mobile screenshots
 * Uses Tesseract to perform OCR and extracts time patterns
 */

class OCRProcessor {
  private $tempDir = '/tmp/gestion_horas_ocr';
  
  public function __construct() {
    if (!is_dir($this->tempDir)) {
      mkdir($this->tempDir, 0777, true);
    }
  }
  
  /**
   * Process an image file and extract time entries
   * @param string $imagePath Path to image file
   * @return array Array of extracted records with fechaISO and horas_slots
   */
  public function processImage($imagePath) {
    if (!file_exists($imagePath)) {
      return ['error' => 'File not found'];
    }
    
    // Check if it's a valid image
    $info = getimagesize($imagePath);
    if ($info === false) {
      return ['error' => 'Invalid image file'];
    }
    
    // Run OCR on the image
    $ocrText = $this->runOCR($imagePath);
    if (empty($ocrText)) {
      return ['error' => 'No text detected in image (OCR failed)'];
    }
    
    // Extract time patterns from OCR text
    $records = $this->extractTimePatterns($ocrText);
    return ['success' => true, 'records' => $records, 'raw_text' => $ocrText];
  }
  
  /**
   * Run Tesseract OCR on image
   * @param string $imagePath
   * @return string OCR text output
   */
  private function runOCR($imagePath) {
    $outputFile = $this->tempDir . '/' . uniqid('ocr_');
    
    // Run tesseract command
    $cmd = sprintf(
      'tesseract %s %s -l eng 2>/dev/null',
      escapeshellarg($imagePath),
      escapeshellarg($outputFile)
    );
    
    exec($cmd, $output, $returnCode);
    
    // Read the output text file
    $textFile = $outputFile . '.txt';
    if (file_exists($textFile)) {
      $text = file_get_contents($textFile);
      unlink($textFile);
      return $text;
    }
    
    return '';
  }
  
  /**
   * Extract time patterns from OCR text
   * Looks for patterns like: HH:MM - HH:MM or just HH:MM
   * Ignores: "Jornada" lines, the first time (screenshot timestamp), and invalid times
   * Valid working times: 4:00 to 23:59 (0-3 hours are likely durations or errors)
   * @param string $text OCR extracted text
   * @return array Records with extracted times
   */
  private function extractTimePatterns($text) {
    $records = [];
    
    // Split by lines and look for time patterns
    $lines = explode("\n", $text);
    
    // Pattern to match times: HH:MM or H:MM
    $timePattern = '/(\d{1,2}):(\d{2})/';
    
    $currentDate = date('Y-m-d'); // Will try to extract date from text
    $extractedDate = $this->extractDate($text);
    if ($extractedDate) {
      $currentDate = $extractedDate;
    }
    
    $times = [];
    $skipFirstTime = true; // Skip the first time (screenshot timestamp)
    $foundTimes = 0;
    
    // Extract all time occurrences from text
    foreach ($lines as $line) {
      // Skip "Jornada" lines (expected working hours)
      if (stripos($line, 'jornada') !== false) {
        continue;
      }
      
      // Look for patterns like "7:32" or "10:26"
      if (preg_match_all($timePattern, $line, $matches, PREG_PATTERN_ORDER)) {
        foreach ($matches[0] as $time) {
          // Parse hour and minute
          list($hour, $minute) = explode(':', $time);
          $hour = (int)$hour;
          $minute = (int)$minute;
          
          // Skip first time if it looks like a screenshot timestamp (e.g., 11:36)
          if ($skipFirstTime && $foundTimes === 0) {
            $skipFirstTime = false;
            continue;
          }
          
          // Filter out invalid times:
          // - Hours 0-3 are likely durations (e.g., "00:34" break duration)
          // - Hours 24+ are invalid
          // - Minutes >= 60 are invalid (OCR errors)
          if ($hour >= 4 && $hour < 24 && $minute < 60) {
            $times[] = $time;
            $foundTimes++;
          }
        }
      }
    }
    
    // Group times using intelligent mapping (same as HTML importer)
    // The mapTimesToSlots logic is imported from import.php
    if (count($times) >= 1) {
      // Use the intelligent mapping that considers the number of times
      // This will be validated and remapped by the import.php mapTimesToSlots function
      $records[] = [
        'fechaISO' => $currentDate,
        'horas' => $times,  // Raw times array - will be intelligently mapped on server
        'raw_times' => $times
      ];
    }
    
    return $records;
  }
  
  /**
   * Try to extract date from OCR text
   * Looks for patterns like "30 dic 2025" or "2025-12-30"
   * @param string $text
   * @return string|null ISO format date (YYYY-MM-DD)
   */
  private function extractDate($text) {
    // Spanish date pattern: "30 dic 2025"
    $spanishMonths = [
      'ene' => '01', 'feb' => '02', 'mar' => '03', 'abr' => '04',
      'may' => '05', 'jun' => '06', 'jul' => '07', 'ago' => '08',
      'sep' => '09', 'oct' => '10', 'nov' => '11', 'dic' => '12'
    ];
    
    // Try Spanish date pattern: "30 dic 2025"
    if (preg_match('/(\d{1,2})\s+(' . implode('|', array_keys($spanishMonths)) . ')\s+(\d{4})/i', $text, $matches)) {
      $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
      $month = $spanishMonths[strtolower($matches[2])];
      $year = $matches[3];
      return "$year-$month-$day";
    }
    
    // Try ISO date pattern: "2025-12-30"
    if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $text, $matches)) {
      return $matches[0];
    }
    
    // Try US date pattern: "12/30/2025"
    if (preg_match('/(\d{2})\/(\d{2})\/(\d{4})/', $text, $matches)) {
      return $matches[3] . '-' . $matches[1] . '-' . $matches[2];
    }
    
    return null;
  }
  
  /**
   * Clean up temporary files
   */
  public function cleanup($imagePath = null) {
    if ($imagePath && file_exists($imagePath)) {
      unlink($imagePath);
    }
    
    // Clean old temp files (older than 1 hour)
    $files = glob($this->tempDir . '/*');
    $now = time();
    foreach ($files as $file) {
      if (is_file($file) && ($now - filemtime($file)) > 3600) {
        unlink($file);
      }
    }
  }
}
?>
