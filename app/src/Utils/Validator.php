<?php
// =============================================================================
// src/Utils/Validator.php
// Lightweight input validation. Returns an array of field errors or empty array.
// Usage:
//   $v = new Validator($_POST);
//   $v->required('username')->minLength('username', 3)->maxLength('username', 32);
//   if ($v->fails()) Response::error('Validation failed', 400, $v->errors());
// =============================================================================

declare(strict_types=1);

namespace PBBG\Utils;

class Validator
{
    private array $data;
    private array $errors = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    // -------------------------------------------------------------------------
    // Rules
    // -------------------------------------------------------------------------

    public function required(string $field): static
    {
        if (!isset($this->data[$field]) || trim((string)$this->data[$field]) === '') {
            $this->errors[$field][] = "{$field} is required.";
        }
        return $this;
    }

    public function minLength(string $field, int $min): static
    {
        $val = $this->data[$field] ?? '';
        if (mb_strlen((string)$val) < $min) {
            $this->errors[$field][] = "{$field} must be at least {$min} characters.";
        }
        return $this;
    }

    public function maxLength(string $field, int $max): static
    {
        $val = $this->data[$field] ?? '';
        if (mb_strlen((string)$val) > $max) {
            $this->errors[$field][] = "{$field} cannot exceed {$max} characters.";
        }
        return $this;
    }

    public function email(string $field): static
    {
        $val = $this->data[$field] ?? '';
        if (!filter_var($val, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field][] = "{$field} must be a valid email address.";
        }
        return $this;
    }

    public function alphaNum(string $field): static
    {
        $val = $this->data[$field] ?? '';
        if (!preg_match('/^[a-zA-Z0-9_]+$/', (string)$val)) {
            $this->errors[$field][] = "{$field} may only contain letters, numbers and underscores.";
        }
        return $this;
    }

    public function inArray(string $field, array $allowed): static
    {
        $val = $this->data[$field] ?? '';
        if (!in_array($val, $allowed, true)) {
            $list = implode(', ', $allowed);
            $this->errors[$field][] = "{$field} must be one of: {$list}.";
        }
        return $this;
    }

    public function integer(string $field, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): static
    {
        $val = $this->data[$field] ?? null;
        if (!is_numeric($val) || (int)$val != $val) {
            $this->errors[$field][] = "{$field} must be an integer.";
        } elseif ((int)$val < $min || (int)$val > $max) {
            $this->errors[$field][] = "{$field} must be between {$min} and {$max}.";
        }
        return $this;
    }

    // -------------------------------------------------------------------------
    // Results
    // -------------------------------------------------------------------------

    public function fails(): bool
    {
        return !empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Return a sanitised value from input data.
     */
    public function get(string $field, mixed $default = null): mixed
    {
        return isset($this->data[$field]) ? $this->data[$field] : $default;
    }
}
