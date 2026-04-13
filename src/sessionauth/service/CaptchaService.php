<?php
declare(strict_types=1);

namespace sessionauth\service;

final class CaptchaService{
	public function __construct(
		private array $captchaConfig,
		private string $language
	){}

	/**
	 * @return array{mode: string, question: string, answer: string, options?: list<string>}
	 */
	public function createChallenge() : array{
		$mode = strtolower((string) ($this->captchaConfig["mode"] ?? "math"));
		return match($mode){
			"button" => $this->createButtonChallenge(),
			"code" => $this->createCodeChallenge(),
			default => $this->createMathChallenge()
		};
	}

	/**
	 * @param array{mode: string, question: string, answer: string, options?: list<string>} $challenge
	 */
	public function validate(array $challenge, mixed $response) : bool{
		$answer = trim((string) $response);
		if($challenge["mode"] === "button"){
			return $answer === $challenge["answer"];
		}

		return $answer !== "" && $answer === $challenge["answer"];
	}

	private function createMathChallenge() : array{
		$left = random_int(1, 9);
		$right = random_int(1, 9);
		$op = random_int(0, 1) === 0 ? "+" : "-";
		if($op === "-" && $right > $left){
			[$left, $right] = [$right, $left];
		}
		$answer = $op === "+" ? $left + $right : $left - $right;

		return [
			"mode" => "math",
			"question" => $this->text("captcha_math_question", ["left" => (string) $left, "op" => $op, "right" => (string) $right], "Solve: {left} {op} {right}"),
			"answer" => (string) $answer
		];
	}

	private function createButtonChallenge() : array{
		$correct = random_int(1, 9);
		$options = [$correct];
		while(count($options) < 3){
			$next = random_int(1, 9);
			if(!in_array($next, $options, true)){
				$options[] = $next;
			}
		}
		shuffle($options);

		return [
			"mode" => "button",
			"question" => $this->text("captcha_button_question", ["number" => (string) $correct], "Press number {number}"),
			"answer" => (string) $correct,
			"options" => array_map(static fn(int $value) : string => (string) $value, $options)
		];
	}

	private function createCodeChallenge() : array{
		$digits = max(4, (int) ($this->captchaConfig["digits"] ?? 4));
		$code = "";
		for($i = 0; $i < $digits; $i++){
			$code .= (string) random_int(0, 9);
		}

		return [
			"mode" => "code",
			"question" => $this->text("captcha_code_question", ["digits" => (string) $digits], "Enter {digits} digits"),
			"answer" => $code
		];
	}

	/**
	 * @param array<string, string> $replace
	 */
	private function text(string $key, array $replace, string $fallback) : string{
		$messages = $this->messages();
		$value = $messages[$key] ?? $fallback;
		foreach($replace as $needle => $replacement){
			$value = str_replace("{" . $needle . "}", $replacement, $value);
		}
		return $value;
	}

	/**
	 * @return array<string, string>
	 */
	private function messages() : array{
		return [
			"captcha_math_question" => $this->language === "ru" ? "Реши: {left} {op} {right}" : "Solve: {left} {op} {right}",
			"captcha_button_question" => $this->language === "ru" ? "Нажми число {number}" : "Press number {number}",
			"captcha_code_question" => $this->language === "ru" ? "Введи {digits} цифр" : "Enter {digits} digits"
		];
	}
}
