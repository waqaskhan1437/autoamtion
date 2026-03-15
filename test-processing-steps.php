<?php
// Test the actual text processing function used in AI Tagline Generator
require_once 'config.php';
require_once 'api/ai-tagline-generator.php';

// 1. Test data from Cohere API
$apiResponse1 = '{
  "id": "55e054f7-fe80-48c5-a82f-055cfba34b5a",
  "message": {
    "role": "assistant",
    "content": [
      {
        "type": "text",
        "text": "[
          {\"top\": \"Birthday Bliss\", \"bottom\": \"Order Now\"},
          {\"top\": \"Celebrate You\", \"bottom\": \"Order Today\"},
          {\"top\": \"Party Time\", \"bottom\": \"Order Here\"}
        ]"
      }
    ]
  },
  "finish_reason": "COMPLETE",
  "usage": {
    "billed_units": {
      "input_tokens": 97,
      "output_tokens": 48
    },
    "tokens": {
      "input_tokens": 592,
      "output_tokens": 50
    },
    "cached_tokens": 0
  }
}';

// 2. Data with code blocks
$apiResponse2 = '{
  "id": "fefef7d8-afa3-4987-b041-dce40ab0a019",
  "message": {
    "role": "assistant",
    "content": [
      {
        "type": "text",
        "text": "```json\n{\n  \"top\": \"Birthday Bliss\",\n  \"bottom\": \"Order Now\"\n}\n```"
      }
    ]
  },
  "finish_reason": "COMPLETE",
  "usage": {
    "billed_units": {
      "input_tokens": 91,
      "output_tokens": 24
    },
    "tokens": {
      "input_tokens": 586,
      "output_tokens": 26
    },
    "cached_tokens": 0
  }
}';

function process_response($apiResponse) {
    $data = json_decode($apiResponse, true);
    
    if (!$data || !isset($data['message']['content'][0]['text'])) {
        echo "❌ Invalid API response format\n";
        return false;
    }
    
    $text = $data['message']['content'][0]['text'];
    echo "\n=== Extracting Text from API Response ===\n";
    var_dump($text);
    echo str_repeat("-", 60) . "\n";
    
    $text = trim($text);
    $text = preg_replace('/^```json\s*/i', '', $text);
    $text = preg_replace('/\s*```$/i', '', $text);
    
    echo "=== After cleaning ===\n";
    var_dump($text);
    echo str_repeat("-", 60) . "\n";
    
    $taglines = json_decode($text, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "❌ JSON decode failed: " . json_last_error_msg() . "\n";
        return false;
    }
    
    if (is_array($taglines)) {
        $valid = [];
        foreach ($taglines as $t) {
            if (isset($t['top']) && isset($t['bottom'])) {
                $valid[] = [
                    'top' => trim($t['top']),
                    'bottom' => trim($t['bottom'])
                ];
            }
        }
        
        echo "\n=== Valid taglines: " . count($valid) . " ===\n";
        var_dump($valid);
        return ['success' => true, 'taglines' => $valid];
    }
    
    return false;
}

echo "=== Test 1: Response without code blocks ===\n";
$result1 = process_response($apiResponse1);

if ($result1) {
    echo "\n✅ Success: " . count($result1['taglines']) . " taglines found\n";
} else {
    echo "\n❌ Failed\n";
}

echo "\n" . str_repeat("-", 80) . "\n\n";

echo "=== Test 2: Response with code blocks ===\n";
$result2 = process_response($apiResponse2);

if ($result2) {
    echo "\n✅ Success: " . count($result2['taglines']) . " taglines found\n";
} else {
    echo "\n❌ Failed\n";
}