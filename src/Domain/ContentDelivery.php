<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * How a job's bytes reach the agent: inside the claim response, or fetched from `content_url` (section 7).
 */
enum ContentDelivery: string
{
    case Inline = 'inline';
    case Url = 'url';
}
