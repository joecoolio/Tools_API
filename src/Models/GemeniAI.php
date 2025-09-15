<?php

namespace App\Models;

// use Gemini\Data\GenerationConfig;
// use Gemini\Enums\ResponseMimeType;
use Gemini;

class GemeniAI extends BaseModel {

    public function getCategoryForTool(string $toolDescription): array {
        $query = "
1 = Car Tools,
2 = Hand Tools,
3 = Power Tools,
4 = Plumbing,
5 = Farm Implement,
6 = Yard Maintenance,
7 = Home Maintenance
Which of those categories best fits the product \"$toolDescription\"? Give the answer as json with keys id and name.";

        return $this->runQuery($query);
    }

    public function getKeywordsForTool(string $toolDescription): array {
        $query = "create a json array of 8 search keywords for this tool and return only the array, nothing else: $toolDescription";
        
        return $this->runQuery($query);
    }

    private function runQuery(string $query): array {
        $gemeniApiKey = $_ENV['GEMENI_API_KEY'];
        $client = Gemini::client($gemeniApiKey);

        // gemma-3-4b-it
        $model = "gemma-3-4b-it";
        $result = $client->generativeModel(model: $model)
        //    ->withGenerationConfig(
        //         generationConfig: new GenerationConfig(
        //             responseMimeType: ResponseMimeType::APPLICATION_JSON,
        //         )
        //     )
        ->generateContent($query);
        
        return $this->parseJsonBlock($result->text());
    }

    // The gemma models return ``` json [] ```
    // Remove that crap and return a parsed array.
    private function parseJsonBlock(string $input): array {
        // Trim leading/trailing whitespace
        $trimmed = trim($input);

        // Remove starting ```json and ending ```
        if (str_starts_with($trimmed, '```json')) {
            $trimmed = substr($trimmed, 7); // remove first 7 characters
        }
        if (str_ends_with($trimmed, '```')) {
            $trimmed = substr($trimmed, 0, -3); // remove last 3 characters
        }

        // Decode JSON
        $decoded = json_decode(trim($trimmed), true);

        // Handle decoding errors
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            error_log("Invalid response from GeminiAI: $input");
            throw new \InvalidArgumentException('Invalid JSON array format.');
        }

        return $decoded;
    }
}
