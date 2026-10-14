<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NuevaLectura implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $pacienteId;
    public $gotasPorMin;
    public $volRestante;
    public $tiempoRestante;
    public $modo;
    public $timestamp;

    /**
     * Create a new event instance.
     */
    public function __construct($pacienteId, $gotasPorMin, $volRestante, $tiempoRestante, $modo, $timestamp)
    {
        $this->pacienteId = $pacienteId;
        $this->gotasPorMin = $gotasPorMin;
        $this->volRestante = $volRestante;
        $this->tiempoRestante = $tiempoRestante;
        $this->modo = $modo;
        $this->timestamp = $timestamp;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('pacientes'),
        ];
    }

    /**
     * Opcional: Personalizar el nombre del evento de broadcast.
     */
    public function broadcastAs(): string
    {
        return 'nueva.lectura';
    }
}