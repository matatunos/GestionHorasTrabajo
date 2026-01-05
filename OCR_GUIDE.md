# OCR Image Import Guide

## Overview

The system now supports importing work hours directly from mobile screenshots using Optical Character Recognition (OCR). This feature extracts times from images captured with time-tracking apps and automatically maps them to the correct work schedule slots.

## How It Works

### 1. **Image Upload**
- Navigate to the Import page: `/import.php`
- Scroll down to the "Or import from screenshot (OCR)" section
- Select an image file from your mobile app (JPG, PNG, or GIF)
- Maximum file size: 10MB

### 2. **Tesseract OCR Processing**
The system uses Tesseract 5.5.0 to extract text from the image:
- Recognizes time patterns (HH:MM format)
- Detects dates in Spanish (30 dic 2025), ISO (2025-12-30), or US (12/30/2025) formats
- Ignores "Jornada" (expected working hours) lines
- Filters out invalid times (durations less than 4 hours)
- Skips the first time (screenshot timestamp)

### 3. **Intelligent Time Mapping**
Extracted times are intelligently mapped based on their count:

| Times | Mapping |
|-------|---------|
| 1 time | Entrada (entry) |
| 2 times | Entrada + Salida (exit) |
| 3 times | Entrada + Salida café + Salida |
| **4 times** | Entrada + Salida café + Entrada café + Salida (YOUR CASE) |
| 5 times | Entrada + Salida café + Entrada café + Salida comida + Salida |
| 6+ times | Full day mapping (all slots) |

### 4. **Preview & Review**
- Extracted data appears in a preview table
- Shows raw OCR text (first 500 characters)
- Displays each record with 7 columns (Fecha, Entrada, Salida café, Entrada café, Salida comida, Entrada comida, Salida)
- Review before importing to database

### 5. **Import to Database**
- Click "Importar estos datos" to save records
- Uses UPSERT logic: overwrites existing entries for the same date/user
- Preserves notes field if already exists

## Technical Architecture

### Files Involved

1. **ocr_processor.php** (NEW)
   - `OCRProcessor` class handles image processing
   - Methods:
     - `processImage($imagePath)`: Main entry point
     - `runOCR($imagePath)`: Executes Tesseract CLI
     - `extractTimePatterns($text)`: Parses time data
     - `extractDate($text)`: Detects date formats
     - `cleanup($imagePath)`: Removes temp files
   - Returns: Array with success, records, and raw OCR text

2. **import.php** (UPDATED)
   - `mapTimesToSlots($times)`: Intelligent time mapping function
   - POST handler for OCR file uploads
   - Preview rendering
   - Integration with existing database import logic

### Data Flow

```
Mobile Screenshot
        ↓
    [Upload]
        ↓
OCRProcessor::processImage()
        ↓
  Tesseract OCR
        ↓
Extract times + dates
        ↓
Filter invalid times
        ↓
mapTimesToSlots()
        ↓
    [Preview]
        ↓
    [Import]
        ↓
   Database
```

## Test Results

### Unit Tests
- ✅ mapTimesToSlots() with 2, 3, 4, 5, and 6 time inputs
- ✅ Time filtering (skips 00:34 duration, accepts valid work times)
- ✅ Date extraction in Spanish, ISO, and US formats

### Integration Tests
- ✅ Real image OCR processing with Tesseract
- ✅ Complete flow: OCR → Mapping → JSON encoding → Database ready
- ✅ Sample 4-time scenario: 7:32, 10:26, 11:00, 14:16 → Correctly mapped to all slots

### Example Output
```
Input:  11:36, 7:32, 10:26, 11:00, 14:16 (raw OCR)
Filter: Skip 11:36 (first), keep others
Output: 7:32, 10:26, 11:00, 14:16
Mapped:
  [0] Entrada: 7:32
  [1] Salida café: 10:26
  [2] Entrada café: 11:00
  [5] Salida: 14:16
```

## Requirements

- **Tesseract OCR**: Version 5.5.0+ (must be installed system-wide)
  - Linux: `apt install tesseract-ocr`
  - macOS: `brew install tesseract`
  - Verify: `tesseract --version`

- **PHP GD or ImageMagick**: For optional image preprocessing (fallback to raw OCR)

- **Temporary Directory**: `/tmp/gestion_horas_ocr` (created automatically)

## Supported Image Formats

- JPEG (.jpg, .jpeg)
- PNG (.png)
- GIF (.gif)
- Any format supported by Tesseract

## File Size Limits

- Maximum: 10MB per image
- Recommended: 2-5MB (higher quality, faster processing)

## Troubleshooting

### "OCR processing failed"
- Tesseract not installed: Install via package manager
- Image too small: Ensure screenshot is at least 800px wide
- Poor image quality: Ensure good lighting, no blur

### "No times detected"
- Check if OCR extracted text (shown in preview)
- Ensure times are in HH:MM format (e.g., "7:32" not "7.32")
- Verify image contains time data

### "Invalid date format"
- Ensure date is in one of supported formats:
  - Spanish: "30 dic 2025"
  - ISO: "2025-12-30"
  - US: "12/30/2025"

## Future Enhancements

1. Batch image upload (process multiple screenshots)
2. Confidence scoring (show OCR reliability percentage)
3. Manual time editing in preview before import
4. Automatic image enhancement (contrast, rotation)
5. Support for more date/time formats

## Code Examples

### Direct OCR Usage

```php
require_once 'ocr_processor.php';

$ocr = new OCRProcessor();
$result = $ocr->processImage('/path/to/image.png');

if ($result['success']) {
    foreach ($result['records'] as $record) {
        echo "Date: " . $record['fechaISO'];
        echo "Times: " . implode(", ", $record['horas']);
    }
}
```

### Manual Time Mapping

```php
require_once 'import.php';

$times = ['7:32', '10:26', '11:00', '14:16'];
$slots = mapTimesToSlots($times);

// Result:
// $slots[0] = '7:32' (entrada)
// $slots[1] = '10:26' (salida café)
// $slots[2] = '11:00' (entrada café)
// $slots[5] = '14:16' (salida)
```

## Testing

Run validation tests:

```bash
# Test mapTimesToSlots function
php test_ocr.php

# Test with real image
php test_ocr_real.php

# Test complete flow
php test_ocr_complete_flow.php
```

All tests should show ✓ success indicators.

---

**Last Updated**: Feature Implementation Complete
**Status**: Production Ready ✓
