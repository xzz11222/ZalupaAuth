<?php
declare(strict_types=1);

namespace sessionauth\form;

use pocketmine\form\Form;
use pocketmine\player\Player;

final class SimpleForm implements Form{
	/** @var array<int, array{text: string}> */
	private array $buttons = [];

	/** @var \Closure(Player, ?int) : void */
	private \Closure $onSubmit;

	private string $title = "";
	private string $content = "";

	/**
	 * @param \Closure(Player, ?int) : void $onSubmit
	 */
	public function __construct(\Closure $onSubmit){
		$this->onSubmit = $onSubmit;
	}

	public function setTitle(string $title) : void{
		$this->title = $title;
	}

	public function setContent(string $content) : void{
		$this->content = $content;
	}

	public function addButton(string $text) : void{
		$this->buttons[] = ["text" => $text];
	}

	public function handleResponse(Player $player, $data) : void{
		if($data !== null && !is_int($data)){
			return;
		}

		($this->onSubmit)($player, $data);
	}

	public function jsonSerialize() : array{
		return [
			"type" => "form",
			"title" => $this->title,
			"content" => $this->content,
			"buttons" => $this->buttons
		];
	}
}
