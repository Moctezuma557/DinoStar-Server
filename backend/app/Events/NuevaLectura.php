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

    /** @var list<string> Alert types generated for this reading only. */
    public array $alertas;

    public ?int $lecturaId;

    public ?int $sesionId;

    /**
     * Create a new event instance.
     */
    public function __construct($pacienteId, $gotasPorMin, $volRestante, $tiempoRestante, $modo, $timestamp, array $alertas = [], ?int $lecturaId = null, ?int $sesionId = null)
    {
        $this->pacienteId = $pacienteId;
        $this->gotasPorMin = $gotasPorMin;
        $this->volRestante = $volRestante;
        $this->tiempoRestante = $tiempoRestante;
        $this->modo = $modo;
        $this->timestamp = $timestamp;
        $this->alertas = $alertas;
        $this->lecturaId = $lecturaId;
        $this->sesionId = $sesionId;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
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
