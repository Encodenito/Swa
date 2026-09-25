<?php

namespace App\Http\Controllers;

use App\Http\Requests\Chatbot\AskRequest;
use App\Http\Requests\Chatbot\HandoffRequest;
use App\Services\ChatbotService;
use App\Services\ChatHandoffService;
use Illuminate\Http\JsonResponse;

/**
 * Public FAQ chatbot (spec B: /chatbot/*, public, throttled). Answers are plain text.
 */
class ChatbotController extends Controller
{
    public function ask(AskRequest $request, ChatbotService $bot): JsonResponse
    {
        return response()->json($bot->answer($request->validated('question')));
    }

    public function handoff(HandoffRequest $request, ChatHandoffService $handoffs): JsonResponse
    {
        $handoffs->create(
            $request->validated('name'),
            $request->validated('contact'),
            $request->validated('message'),
            $request->transcript(),
            $request->user(),
        );

        return response()->json(['message' => 'Thanks! Our staff will contact you during shop hours.'], 201);
    }
}
