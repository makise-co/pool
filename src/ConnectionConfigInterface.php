<?php
/*
 * This file is part of the Makise-Co Framework
 *
 * World line: 0.571024a
 * (c) Dmitry K. <coder1994@gmail.com>
 */

declare(strict_types=1);

namespace MakiseCo\Pool;

interface ConnectionConfigInterface
{
    public function getHost(): string;

    public function getPort(): int;

    public function getUser(): ?string;

    public function getPassword(): ?string;

    public function toArray(): array;

    public function __toString(): string;
}
