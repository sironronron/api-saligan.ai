<?php

namespace App\Enums;

enum ChatProvider: string
{
    case Ollama = 'ollama';
    case Gemini = 'gemini';
}
