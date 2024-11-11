<?php

require 'apps/mail/composer/autoload.php';

//use Codewithkyrian\Transformers\Pipelines\Pipeline;
use function Codewithkyrian\Transformers\Pipelines\pipeline;

function testBartModel() {
    try {
        // Initialize the zero-shot classification pipeline
        $classifier = pipeline("zero-shot-classification");

        // Define sample text and candidate labels
        $sampleText = "Hey how are you doing,


do you have any idea what we could do on chistmas? Maybe go on vacation in italy?

Greetings,
Emma
";
        $candidateLabels = ['Important', 'Work', 'Personal', 'To Do', 'Later'];

        // Run classification
        echo "Running classification on sample text...\n";
        $result = $classifier($sampleText, $candidateLabels);

        // Display results
        echo "Classification result:\n";
        print_r($result);

        echo "\nTest completed successfully.\n";
    } catch (\Throwable $e) {
        // Log the error message if the model fails to load or classify
        echo "Error: Could not load or use BART model for zero-shot classification.\n";
        echo "Error message: " . $e->getMessage() . "\n";
    }
}

testBartModel();
