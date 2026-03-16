<?php

test('example', function () {
    $response = $this->getJson('/api/v1/health');

    $response->assertStatus(200);
});
