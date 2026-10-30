<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Unit;

final class DirectivesAndMentalModelsTest extends ClientTestCase
{
    private const DIRECTIVE = ['id' => 'd1', 'bank_id' => 'support', 'name' => 'Tone', 'content' => 'Answer politely.'];

    public function test_directives(): void
    {
        $this->respond('POST', '/v1/default/banks/support/directives', self::DIRECTIVE);
        $this->assertSame('d1', $this->hindsight->createDirective('support', 'Tone', 'Answer politely.', priority: 10, tags: ['tone'])['id']);
        $this->assertSent('POST', '/v1/default/banks/support/directives');
        $this->assertSame(['name' => 'Tone', 'content' => 'Answer politely.', 'priority' => 10, 'is_active' => true, 'tags' => ['tone']], $this->http->lastJson());

        $this->http->json(['items' => [self::DIRECTIVE]]);
        $this->hindsight->listDirectives('support', tags: ['tone'], activeOnly: false);
        $this->assertSent('GET', '/v1/default/banks/support/directives?tags=tone&active_only=false');

        $this->respond('GET', '/v1/default/banks/support/directives/d1', self::DIRECTIVE);
        $this->hindsight->getDirective('support', 'd1');
        $this->assertSent('GET', '/v1/default/banks/support/directives/d1');

        $this->respond('PATCH', '/v1/default/banks/support/directives/d1', self::DIRECTIVE);
        $this->hindsight->updateDirective('support', 'd1', ['is_active' => false]);
        $this->assertSent('PATCH', '/v1/default/banks/support/directives/d1');
        $this->assertSame(['is_active' => false], $this->http->lastJson());

        $this->http->json(['success' => true]);
        $this->hindsight->deleteDirective('support', 'd1');
        $this->assertSent('DELETE', '/v1/default/banks/support/directives/d1');
    }

    public function test_mental_models(): void
    {
        $this->respond('POST', '/v1/default/banks/support/mental-models', ['mental_model_id' => 'mm1', 'operation_id' => 'op-1']);
        $created = $this->hindsight->createMentalModel('support', 'Customer profile', 'What do we know about this customer?', id: 'mm1', tags: ['user:42'], maxTokens: 1024);
        $this->assertSame('op-1', $created['operation_id']);
        $this->assertSent('POST', '/v1/default/banks/support/mental-models');
        $this->assertSame(['name' => 'Customer profile', 'source_query' => 'What do we know about this customer?', 'id' => 'mm1', 'tags' => ['user:42'], 'max_tokens' => 1024], $this->http->lastJson());

        $this->http->json(['items' => []]);
        $this->hindsight->listMentalModels('support', tags: ['user:42'], detail: 'metadata');
        $this->assertSent('GET', '/v1/default/banks/support/mental-models?tags=user%3A42&detail=metadata');

        $this->http->json(['id' => 'mm1', 'content' => 'Prefers email.']);
        $this->assertSame('Prefers email.', $this->hindsight->getMentalModel('support', 'mm1')['content']);
        $this->assertSent('GET', '/v1/default/banks/support/mental-models/mm1');

        $this->http->json(['id' => 'mm1']);
        $this->hindsight->updateMentalModel('support', 'mm1', ['source_query' => 'What does this customer prefer?']);
        $this->assertSent('PATCH', '/v1/default/banks/support/mental-models/mm1');
        $this->assertSame(['source_query' => 'What does this customer prefer?'], $this->http->lastJson());

        $this->respond('POST', '/v1/default/banks/support/mental-models/mm1/refresh', ['operation_id' => 'op-2', 'status' => 'queued']);
        $this->assertSame('op-2', $this->hindsight->refreshMentalModel('support', 'mm1')['operation_id']);
        $this->assertSent('POST', '/v1/default/banks/support/mental-models/mm1/refresh');

        $this->http->json(['success' => true]);
        $this->hindsight->deleteMentalModel('support', 'mm1');
        $this->assertSent('DELETE', '/v1/default/banks/support/mental-models/mm1');
    }
}
