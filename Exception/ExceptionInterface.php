<?php

namespace Omnisense\Contracts\Exception;

/**
 * Every exception omnisense/contracts throws, and every family's own: a
 * family's exception interface extends it (OmnitravelException...), so one
 * catch holds them all.
 */
interface ExceptionInterface extends \Throwable
{
}
