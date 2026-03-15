<?php
// Test JSON decoding of response with escape issues

// Response from Cohere with actual formatting
$rawJson = '{"id":"55e054f7-fe80-48c5-a82f-055cfba34b5a","message":{"role":"assistant","content":[{"type":"text","text":"[
  {\"top\": \"Birthday Bliss\", \"bottom\": \"Order Now\"},
  {\"top\": \"Celebrate You\", \"bottom\": \"Order Today\"},
  {\"top\": \"Party Time\", \"bottom\": \"Order Here\"}
]"}],"finish_reason":"COMPLETE","usage":{"billed_units":{"input_tokens":97,"output_tokens":48},"tokens":{"input_tokens":592,"output_tokens":50},"cached_tokens":0}}}';

// Try to decode
$data = json_decode($rawJson, true);

if ($data === null) {
    echo "JSON decode failed: " . json_last_error_msg() . "\n";
    
    // Check position of error
    $pos = json_last_error_pos();
    if ($pos !== 0) {
        $context = substr($rawJson, max(0, $pos - 20), 40);
        echo "Error near position $pos: \"$context\"\n";
    }
    
    // Try to fix
    $fixed = str_replace(['\"', "\n", "\r\n"], ['"', '', ''], $rawJson);
    $fixedData = json_decode($fixed, true);
    
    if ($fixedData) {
        echo "Fixed JSON decode successful\n";
        print_r($fixedData);
    } else {
        echo "Fixed JSON also failed: " . json_last_error_msg() . "\n";
    }
} else {
    echo "Successfully decoded JSON\n";
    print_r($data);
}