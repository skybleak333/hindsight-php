<?php

declare(strict_types=1);

use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\TagsMatch;

require __DIR__.'/../vendor/autoload.php';

$hindsight = new Hindsight(
    getenv('HINDSIGHT_URL') ?: 'http://127.0.0.1:8888',
    apiKey: getenv('HINDSIGHT_API_KEY') ?: null,
);

$bank = 'php-knowledge-example-'.bin2hex(random_bytes(6));
$hindsight->createKnowledgeBank($bank);

try {
    $hindsight->ingestKnowledgeTexts($bank, [[
        'document_id' => 'atlas-returns',
        'filename' => 'atlas-returns.md',
        'text' => "# Atlas Returns\n\nAtlas kettles can be returned within fourteen days of delivery.",
        'tags' => ['approved'],
    ]]);

    $found = $hindsight->searchKnowledgeBank(
        $bank,
        'What is the return window for an Atlas kettle?',
        maxTokens: 512,
        tags: ['approved'],
        tagsMatch: TagsMatch::AllStrict,
        options: ['enable_reranking' => false],
    );
    $passages = $found['results'];
    if ($passages === []) {
        throw new RuntimeException('The knowledge bank did not return the policy.');
    }

    foreach ($passages as $passage) {
        printf("%s · passage %d\n%s\n", $passage['filename'] ?? $passage['document_id'], $passage['index'], $passage['text']);
    }
} finally {
    $hindsight->deleteKnowledgeBank($bank);
}
