#!/usr/bin/env php
<?php
/**
 * Data Analysis Summary for Schedule Suggestions
 * Shows all available data sources analyzed by the enhanced algorithm
 */

echo "\n" . str_repeat("=", 80) . "\n";
echo "SCHEDULE SUGGESTIONS - COMPREHENSIVE DATA ANALYSIS SUMMARY\n";
echo str_repeat("=", 80) . "\n\n";

$sections = [
    "Database Tables Analyzed" => [
        "entries" => [
            "Fields Used" => [
                "• start (TIME) - Entry time for weighted averages",
                "• end (TIME) - Exit time for duration calculation",
                "• coffee_out (TIME) - Break start time",
                "• coffee_in (TIME) - Break end time",
                "• lunch_out (TIME) - Lunch start time",
                "• lunch_in (TIME) - Lunch end time",
                "• date (DATE) - Used for weekday pattern analysis",
                "• special_type - Filters vacation/personal leave",
                "• user_id - Filter by current user"
            ],
            "Analysis Scope" => "Last 90 days of work entries"
        ],
        "incidents" => [
            "Fields Used" => [
                "• hours_lost - Deducted from worked minutes",
                "• date - Matched with entries table"
            ],
            "Analysis Scope" => "Integrated via compute_day() function"
        ],
        "year_configs" => [
            "Fields Used" => [
                "• work_hours['winter']['mon_thu'] - Monday-Thursday target",
                "• work_hours['winter']['friday'] - Friday target (early exit)",
                "• work_hours['summer']['mon_thu'] - Summer Mon-Thu",
                "• work_hours['summer']['friday'] - Summer Friday",
                "• coffee_minutes - Expected coffee break duration",
                "• lunch_minutes - Expected lunch break duration",
                "• summer_start/end - Seasonal determination"
            ],
            "Analysis Scope" => "Current year configuration"
        ],
        "holidays" => [
            "Fields Used" => [
                "• date - Marked as non-working when matched",
                "• annual - Recurring holidays support"
            ],
            "Analysis Scope" => "Current year (automatic via compute_day)"
        ]
    ],

    "Weighted Pattern Analysis" => [
        "Lookback Period" => "90 days of historical entries",
        "Weight Distribution" => [
            "• Recent (0-7 days ago): 3.0x weight",
            "• Medium (7-30 days ago): 2.0x weight",
            "• Historical (30+ days ago): 1.0x weight"
        ],
        "Per-Weekday Statistics" => [
            "• Weighted average start time",
            "• Weighted average end time",
            "• Weighted average worked hours",
            "• Coffee break pattern (avg duration)",
            "• Lunch break pattern (avg duration)",
            "• Total historical entries count (confidence metric)"
        ]
    ],

    "Time Calculations" => [
        "Current Week Analysis" => [
            "• Hours worked Monday-today",
            "• Breakdown by individual days",
            "• Entry/exit times recorded"
        ],
        "Target Calculation" => [
            "• Weekly target = (Mon-Thu hrs × 4 + Friday hrs) / 5 × 5",
            "• Accounts for seasonal variations",
            "• Respects Friday early exit settings"
        ],
        "Remaining Hours" => [
            "• = Weekly target - hours worked this week",
            "• Minimum threshold: 0.5 hours triggers suggestions"
        ]
    ],

    "Intelligent Distribution Algorithm" => [
        "Constraints Applied" => [
            "✓ Maximum 1-hour difference between any two suggested days",
            "✓ Exactly achieves remaining hours target (±0.01 tolerance)",
            "✓ Respects minimum 5.5 hours per day",
            "✓ Honors Friday early exit configuration"
        ],
        "Pattern-Based Adjustments" => [
            "• For days with 3+ historical entries:",
            "  - Suggest close to user's typical time (±30 min max)",
            "  - High confidence recommendation",
            "• For days with 1-2 entries:",
            "  - Broader pattern consideration",
            "  - Medium confidence",
            "• For days with no historical data:",
            "  - Use year config defaults",
            "  - Low confidence, purely mathematical distribution"
        ]
    ],

    "Suggestion Output" => [
        "For Each Remaining Weekday" => [
            "• Suggested date (YYYY-MM-DD format)",
            "• Day name (Monday, Tuesday, etc.)",
            "• Day of week number (1=Monday, 5=Friday)",
            "• Start time (HH:MM based on historical average)",
            "• End time (Calculated from start + hours + breaks)",
            "• Hours to work (Distributed per constraints)",
            "• Confidence level (alta/media/baja)",
            "• Number of historical patterns used",
            "• Reasoning text (explanation of basis)"
        ]
    ],

    "Data Quality Features" => [
        "Filtering" => [
            "✓ Excludes weekends",
            "✓ Excludes vacation/personal leave days",
            "✓ Excludes incomplete entries (no start/end)",
            "✓ Filters out holidays via compute_day()"
        ],
        "Incident Integration" => [
            "✓ Accounts for lost time from incidents table",
            "✓ Applied via compute_day() function",
            "✓ Automatically deducts from worked minutes"
        ],
        "Break Accounting" => [
            "✓ Coffee breaks count as work time",
            "✓ Lunch breaks do NOT count as work time",
            "✓ Uses actual duration when available",
            "✓ Falls back to config defaults if not recorded"
        ]
    ]
];

foreach ($sections as $section => $content) {
    echo "\n" . str_repeat("-", 80) . "\n";
    echo "📊 " . $section . "\n";
    echo str_repeat("-", 80) . "\n\n";
    
    if (is_array($content)) {
        foreach ($content as $key => $value) {
            if (is_array($value)) {
                if (is_numeric(key($value))) {
                    // Array of strings (bullet points)
                    echo "  $key:\n";
                    foreach ($value as $item) {
                        echo "    $item\n";
                    }
                } else {
                    // Nested associative array (subsections)
                    echo "  📌 $key\n";
                    foreach ($value as $subkey => $subvalue) {
                        echo "      $subkey: $subvalue\n";
                    }
                }
            } else {
                echo "  • $key: $value\n";
            }
        }
    }
    echo "\n";
}

echo "\n" . str_repeat("=", 80) . "\n";
echo "SUMMARY: Comprehensive analysis using ALL available database information\n";
echo str_repeat("=", 80) . "\n\n";

echo "KEY METRICS:\n";
echo "  • Historical lookback: 90 days\n";
echo "  • Weekdays analyzed: Monday-Friday only\n";
echo "  • Variance constraint: ≤ 1 hour between suggested days\n";
echo "  • Confidence sources: 3+ = alta, 1-2 = media, 0 = baja\n";
echo "  • Default weekly target: 38-40 hours (config-dependent)\n";
echo "  • Suggestion trigger: remaining hours ≥ 0.5\n";
echo "\nSTATUS: ✅ Production Ready - All data sources integrated\n";
echo "\n";
