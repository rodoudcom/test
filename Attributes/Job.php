<?php
namespace App\WorkflowRodoud\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Job
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $description = null
    ) {}
}
