<?php

declare(strict_types=1);

it('boots the test harness', function (): void {
    expect(PHP_VERSION_ID)->toBeGreaterThanOrEqual(80300);
});
