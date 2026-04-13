<?php
declare(strict_types=1);

namespace sessionauth\service;

final class AuditTrail{
	public function __construct(
		private string $filePath
	){
		$dir = dirname($this->filePath);
		if(!is_dir($dir)){
			@mkdir($dir, 0777, true);
		}
	}

	public function record(string $event, string $message) : void{
		$line = "[" . date("Y-m-d H:i:s") . "] [$event] $message\n";
		@file_put_contents($this->filePath, $line, FILE_APPEND | LOCK_EX);
	}
}
