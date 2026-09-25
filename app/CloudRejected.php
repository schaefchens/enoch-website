<?php
declare(strict_types=1);
namespace Enoch;
final class CloudRejected extends \RuntimeException {
    public function __construct(public readonly int $httpStatus,public readonly string $apiCode,string $message){parent::__construct($message,$httpStatus);}
    public function isCapacityUnavailable():bool{return in_array($this->apiCode,['resource_unavailable','placement_error'],true);}
}
