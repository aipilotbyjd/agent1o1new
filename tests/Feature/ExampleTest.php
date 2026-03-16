<?php

test('the application returns a successful response', function () {
    $response = $this->getJson('/api/v1/health');

    $response->assertStatus(200);
});
