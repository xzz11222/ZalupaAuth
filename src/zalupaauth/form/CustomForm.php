<?php

declare(strict_types=1);

namespace zalupaauth\form;

use pocketmine\form\Form;
use pocketmine\player\Player;

final class CustomForm implements Form{

    /** @var array<int, array{type: string, text?: string, placeholder?: string, default?: int|string, options?: list<string>}> */
    private array $content = [];

    /** @var \Closure(Player, ?array) : void */
    private \Closure $onSubmit;

    private string $title = "";

    /**
     * @param \Closure(Player, ?array) : void $onSubmit
     */
    public function __construct(\Closure $onSubmit){
        $this->onSubmit = $onSubmit;
    }

    public function setTitle(string $title) : void{
        $this->title = $title;
    }

    public function addInput(string $text, string $placeholder = "", string $default = "") : void{
        $this->content[] = [
            "type" => "input",
            "text" => $text,
            "placeholder" => $placeholder,
            "default" => $default
        ];
    }

    public function addLabel(string $text) : void{
        $this->content[] = [
            "type" => "label",
            "text" => $text
        ];
    }

    public function addDropdown(string $text, array $options, int $default = 0) : void{
        $this->content[] = [
            "type" => "dropdown",
            "text" => $text,
            "options" => array_values($options),
            "default" => $default
        ];
    }

    public function handleResponse(Player $player, $data) : void{
        if($data !== null && !is_array($data)){
            return;
        }

        ($this->onSubmit)($player, $data);
    }

    public function jsonSerialize() : array{
        return [
            "type" => "custom_form",
            "title" => $this->title,
            "content" => $this->content
        ];
    }
}
