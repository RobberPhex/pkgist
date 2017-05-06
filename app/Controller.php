<?php

namespace App;

use React\Http\Request;
use React\Http\Response;

interface Controller{
    public function action(Request $request, Response $response, $parameters);
}