<?php

declare(strict_types=1);

namespace Valres\AutoLoad\attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class CancelAutoLoad
{

}