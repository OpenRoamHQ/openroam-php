<?php
// Hand-written, not generated (see overlay/).

namespace OpenRoam;

/** The request was not signed by OpenRoam with this secret, or is too old. */
class WebhookSignatureException extends \RuntimeException
{
}
