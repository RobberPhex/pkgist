<?php

namespace App\Lib;

class Buffer
{
    private $buffer = '';

    public function write($data)
    {
        $this->buffer .= $data;
    }

    public function read()
    {
        return $this->buffer;
    }

    public function clear()
    {
        $this->buffer = '';
    }
}