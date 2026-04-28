<?php

namespace App\Domain\Session\DTOs;

readonly class StartSessionDTO
{
    public function __construct(
        public string  $classroomId,
        public string  $teacherId,
        public ?string $subject = null,
    ) {}
}
