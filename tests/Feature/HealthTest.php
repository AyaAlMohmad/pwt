<?php

declare(strict_types=1);

it('GET /api/v1/health returns success envelope', function (): void {
    $response = $this->getJson('/api/v1/health');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'ok')
        ->assertJsonPath('data.service', 'pwt-api')
        ->assertJsonStructure([
            'success',
            'message',
            'data' => ['status', 'service', 'time'],
        ]);

    expect($response->json('message'))->toBeString()->not->toBeEmpty();
    expect($response->json())->toBeSuccessEnvelope();
});
