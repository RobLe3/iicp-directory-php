<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Support;

final class TestAppKey
{
    public static function base64(): string
    {
        return 'base64:'.base64_encode(str_repeat("\0", 32));
    }
}
