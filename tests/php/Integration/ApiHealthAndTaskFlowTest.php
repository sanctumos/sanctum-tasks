<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Integration;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use SanctumTasks\Tests\Support\PhpBuiltInServer;

final class ApiHealthAndTaskFlowTest extends TestCase
{
    private ?PhpBuiltInServer $server = null;

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            $this->server->stop();
            $this->server = null;
        }
        parent::tearDown();
    }

    public function testHealthUnauthorizedAndAuthorized(): void
    {
        $this->server = PhpBuiltInServer::start();
        $c = new Client(['base_uri' => $this->server->baseUrl, 'http_errors' => false]);

        $noKey = $c->get('/api/health.php');
        $this->assertSame(401, $noKey->getStatusCode());

        $ok = $c->get('/api/health.php', [
            'headers' => ['X-API-Key' => $this->server->apiKey],
        ]);
        $this->assertSame(200, $ok->getStatusCode());
        $json = json_decode((string)$ok->getBody(), true);
        $this->assertIsArray($json);
        $this->assertTrue($json['success'] ?? false);
    }

    public function testCreateAndListTaskViaHttp(): void
    {
        $this->server = PhpBuiltInServer::start();
        $c = new Client([
            'base_uri' => $this->server->baseUrl,
            'http_errors' => false,
            'headers' => ['X-API-Key' => $this->server->apiKey],
        ]);

        $create = $c->post('/api/create-task.php', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'title' => 'Integration task',
                'status' => 'todo',
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(201, $create->getStatusCode(), (string)$create->getBody());
        $created = json_decode((string)$create->getBody(), true);
        $this->assertIsArray($created);
        $this->assertTrue($created['success'] ?? false);

        $list = $c->get('/api/list-tasks.php?limit=5');
        $this->assertSame(200, $list->getStatusCode());
        $listed = json_decode((string)$list->getBody(), true);
        $this->assertIsArray($listed);
        $this->assertTrue($listed['success'] ?? false);
        $data = $listed['data'] ?? [];
        $this->assertArrayHasKey('tasks', $data);
        $this->assertNotEmpty($data['tasks']);
    }
}
