<?php
declare(strict_types=1);
namespace QarediAssistant;

final class Error extends \RuntimeException
{
    public function __construct(public readonly int $http, public readonly string $kind,
        string $message, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }

    public function payload(?string $runId = null): array
    {
        $data = ['code' => $this->kind, 'message' => $this->getMessage(),
            'retryable' => $this->retryable,
            'request_id' => defined('TBOX_REQUEST_ID') ? TBOX_REQUEST_ID : '-'];
        if ($runId !== null) $data['run_id'] = $runId;
        return $data;
    }
}
