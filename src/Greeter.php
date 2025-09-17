<?php

namespace CodexCrud;

class Greeter
{
    public function greet(string $name): string
    {
        $cleanName = trim($name);
        if ($cleanName === '') {
            $cleanName = 'World';
        }

        return sprintf('Hello, %s!', $cleanName);
    }
}
