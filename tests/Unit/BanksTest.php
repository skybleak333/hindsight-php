<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Unit;

final class BanksTest extends ClientTestCase
{
    private const PROFILE = [
        'bank_id' => 'support',
        'name' => 'Support agent',
        'disposition' => ['skepticism' => 3, 'literalism' => 3, 'empathy' => 4],
        'mission' => 'Remember customer preferences.',
    ];

    public function test_create_bank_puts_the_profile(): void
    {
        $this->respond('PUT', '/v1/default/banks/support', self::PROFILE);

        $profile = $this->hindsight->createBank('support', name: 'Support agent', reflectMission: 'Remember customer preferences.', options: [
            'disposition_empathy' => 4,
            'enable_observations' => false,
        ]);

        $this->assertSent('PUT', '/v1/default/banks/support');
        $this->assertSame([
            'name' => 'Support agent',
            'reflect_mission' => 'Remember customer preferences.',
            'disposition_empathy' => 4,
            'enable_observations' => false,
        ], $this->http->lastJson());
        $this->assertSame('support', $profile['bank_id']);
    }

    public function test_create_bank_with_defaults_sends_an_empty_object(): void
    {
        $this->respond('PUT', '/v1/default/banks/support', self::PROFILE);

        $this->hindsight->createBank('support');

        $this->assertSame('{}', (string) $this->http->lastRequest()->getBody());
    }

    public function test_update_bank_patches_only_given_fields(): void
    {
        $this->respond('PATCH', '/v1/default/banks/support', self::PROFILE);

        $this->hindsight->updateBank('support', reflectMission: 'Remember customer preferences.');

        $this->assertSent('PATCH', '/v1/default/banks/support');
        $this->assertSame(['reflect_mission' => 'Remember customer preferences.'], $this->http->lastJson());
    }

    public function test_list_get_stats_and_delete(): void
    {
        $this->respond('GET', '/v1/default/banks', ['banks' => [['bank_id' => 'support', 'disposition' => self::PROFILE['disposition'], 'matched_aliases' => []]], 'total' => 1, 'limit' => 10, 'offset' => 0]);
        $this->assertSame('support', $this->hindsight->listBanks(search: 'sup', limit: 10)['banks'][0]['bank_id']);
        $this->assertSent('GET', '/v1/default/banks?q=sup&limit=10');

        $this->http->json(['bank_id' => 'support', 'total_nodes' => 10]);
        $this->hindsight->getBankStats('support');
        $this->assertSent('GET', '/v1/default/banks/support/stats');

        $this->respond('DELETE', '/v1/default/banks/support', ['success' => true, 'message' => 'Bank deleted']);
        $this->assertTrue($this->hindsight->deleteBank('support')['success']);
        $this->assertSent('DELETE', '/v1/default/banks/support');
    }

    public function test_bank_config(): void
    {
        $config = ['bank_id' => 'support', 'config' => ['retain_chunk_size' => 2000], 'overrides' => ['retain_chunk_size' => 2000]];

        $this->respond('GET', '/v1/default/banks/support/config', $config);
        $this->assertSame(2000, $this->hindsight->getBankConfig('support')['config']['retain_chunk_size']);
        $this->assertSent('GET', '/v1/default/banks/support/config');

        $this->respond('PATCH', '/v1/default/banks/support/config', $config);
        $this->hindsight->updateBankConfig('support', ['retain_chunk_size' => 2000]);
        $this->assertSent('PATCH', '/v1/default/banks/support/config');
        $this->assertSame(['updates' => ['retain_chunk_size' => 2000]], $this->http->lastJson());

        $this->respond('DELETE', '/v1/default/banks/support/config', ['bank_id' => 'support', 'config' => new \stdClass, 'overrides' => new \stdClass]);
        $this->hindsight->resetBankConfig('support');
        $this->assertSent('DELETE', '/v1/default/banks/support/config');
    }

    public function test_documents(): void
    {
        $this->http->json(['items' => [], 'total' => 0, 'limit' => 50, 'offset' => 0]);
        $this->hindsight->listDocuments('support', search: 'invoice', tags: ['user:42'], limit: 50);
        $this->assertSent('GET', '/v1/default/banks/support/documents?q=invoice&tags=user%3A42&limit=50');

        $this->http->json(['id' => 'doc-1', 'bank_id' => 'support']);
        $this->assertSame('doc-1', $this->hindsight->getDocument('support', 'doc-1')['id']);
        $this->assertSent('GET', '/v1/default/banks/support/documents/doc-1');

        $this->respond('DELETE', '/v1/default/banks/support/documents/doc-1', ['success' => true, 'message' => 'deleted', 'document_id' => 'doc-1', 'memory_units_deleted' => 4]);
        $this->assertSame(4, $this->hindsight->deleteDocument('support', 'doc-1')['memory_units_deleted']);
        $this->assertSent('DELETE', '/v1/default/banks/support/documents/doc-1');
    }
}
