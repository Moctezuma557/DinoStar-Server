<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte de venoclisis - DinoStar</title>
    <style>
        @page { margin: 24pt 28pt 36pt; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; color: #243447; line-height: 1.3; }
        h1 { font-size: 19pt; margin: 3pt 0 4pt; color: #143650; }
        h2 { font-size: 12pt; margin: 12pt 0 7pt; color: #143650; page-break-after: avoid; }
        .nueva-pagina { page-break-before: always; margin-top: 0; }
        p { margin: 0 0 6pt; }
        .marca { color: #167c80; font-size: 11pt; font-weight: bold; letter-spacing: 1pt; }
        .muted { color: #5a6c7d; }
        .filtros { background: #edf4f8; padding: 9pt 11pt; margin: 12pt 0; border-left: 3pt solid #167c80; }
        .kpis { width: 100%; border-collapse: separate; border-spacing: 5pt 0; margin: 10pt -5pt 0; }
        .kpis td { width: 25%; padding: 8pt; background: #143650; color: #fff; vertical-align: top; }
        .kpis .valor { font-size: 19pt; font-weight: bold; margin-top: 5pt; }
        .kpis .nota { font-size: 8pt; color: #d7e6f0; }
        .datos { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .datos thead { display: table-header-group; }
        .datos th { background: #e8f0f5; color: #143650; font-weight: bold; text-align: left; }
        .datos th, .datos td { padding: 5pt 6pt; border-bottom: 1pt solid #d6e1e9; vertical-align: top; word-wrap: break-word; }
        .datos tr { page-break-inside: avoid; }
        .datos tbody tr:nth-child(even) { background: #f5f8fa; }
        .sesiones { font-size: 7.5pt; }
        .sesiones th, .sesiones td { padding: 6pt 4pt; }
        .numero { text-align: right; }
        .vacio { padding: 15pt; background: #f5f8fa; color: #5a6c7d; }
        footer { position: fixed; bottom: -25pt; left: 0; right: 0; color: #5a6c7d; font-size: 8pt; border-top: 1pt solid #d6e1e9; padding-top: 5pt; }
        footer .pagina { float: right; }
        .numero-pagina:before { content: counter(page); }
    </style>
</head>
<body>
    @php
        $fecha = static fn ($valor) => $valor === null
            ? 'En curso'
            : \Carbon\CarbonImmutable::parse($valor)->setTimezone(config('app.timezone'))->format('d/m/Y H:i:s');
        $segundos = static fn ($valor) => $valor === null ? '—' : number_format($valor, 2, '.', ',').' s';
    @endphp

    <footer>
        DinoStar | Reportes de venoclisis
        <span class="pagina">Página <span class="numero-pagina"></span></span>
    </footer>

    <div class="marca">DINOSTAR</div>
    <h1>Reportes y auditoría de venoclisis</h1>
    <p class="muted">Generado: {{ $generado->format('d/m/Y H:i:s') }} | Zona horaria: {{ config('app.timezone') }}</p>

    <div class="filtros">
        <strong>Periodo:</strong> {{ $filtros['desde'] ?? 'Sin límite inicial' }} / {{ $filtros['hasta'] ?? 'Sin límite final' }}<br>
        <strong>Paciente (ID):</strong> {{ $filtros['paciente_id'] ?? 'Todos' }} &nbsp; | &nbsp;
        <strong>Sala:</strong> {{ $filtros['sala'] ?? 'Todas' }} &nbsp; | &nbsp;
        <strong>Enfermera (ID):</strong> {{ $filtros['enfermera_id'] ?? 'Todas' }}
    </div>

    <table class="kpis">
        <tr>
            <td>Total de sesiones<div class="valor">{{ number_format($resumen['total_sesiones']) }}</div></td>
            <td>Sesiones completadas
                <div class="valor">{{ number_format($resumen['sesiones_completadas']['cantidad']) }}</div>
                <div class="nota">{{ number_format($resumen['sesiones_completadas']['porcentaje'], 2) }}% del total</div>
            </td>
            <td>Sesiones con alertas<div class="valor">{{ number_format($resumen['sesiones_con_alertas']) }}</div></td>
            <td>Tiempo promedio de respuesta<div class="valor">{{ $segundos($resumen['tiempo_promedio_respuesta']) }}</div></td>
        </tr>
    </table>

    <h2>Alertas por categoría</h2>
    <table class="datos">
        <thead>
            <tr>
                <th>Categoría</th>
                <th>Cantidad</th>
                <th>Porcentaje</th>
                <th>Promedio de resolución</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($categorias as $categoria)
                <tr>
                    <td>{{ $categoria['tipo'] }}</td>
                    <td>{{ number_format($categoria['cantidad']) }}</td>
                    <td>{{ number_format($categoria['porcentaje'], 2) }}%</td>
                    <td>{{ $segundos($categoria['tiempo_promedio_resolucion']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="muted" style="font-size: 8pt; margin-top: 6pt;">
        Los porcentajes incluyen todas las alertas del periodo en su denominador y pueden sumar menos de 100%.
        Los promedios consideran únicamente atenciones que resolvieron la alerta. — indica que no hay un promedio disponible.
    </p>

    <h2 class="{{ $sesiones->isNotEmpty() ? 'nueva-pagina' : '' }}">Sesiones auditadas ({{ $sesiones->count() }})</h2>
    @if ($sesiones->isEmpty())
        <p class="vacio">No se encontraron sesiones con los filtros seleccionados.</p>
    @else
        <table class="datos sesiones">
            <thead>
                <tr>
                    <th style="width: 15%;">Paciente</th>
                    <th style="width: 14%;">Enfermera</th>
                    <th style="width: 12%;">Ubicación</th>
                    <th style="width: 18%;">Inicio / fin</th>
                    <th style="width: 9%;">Duración</th>
                    <th style="width: 14%;">Solución / volumen</th>
                    <th style="width: 6%;">Alertas</th>
                    <th style="width: 12%;">Estado</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($sesiones as $sesion)
                    <tr>
                        <td>{{ $sesion->paciente }}<br><span class="muted">ID {{ $sesion->paciente_id }} | Sesión {{ $sesion->id }}</span></td>
                        <td>{{ $sesion->enfermera }}</td>
                        <td>{{ $sesion->sala }}<br>Cama: {{ $sesion->numero_cama }}</td>
                        <td>{{ $fecha($sesion->inicio) }}<br>{{ $fecha($sesion->fin) }}</td>
                        <td>{{ $sesion->duracion_segundos === null ? '—' : number_format($sesion->duracion_segundos / 60, 2).' min' }}</td>
                        <td>{{ $sesion->tipo_solucion ?? 'Sin registrar' }}<br>{{ number_format($sesion->vol_total, 2) }} ml</td>
                        <td class="numero">{{ $sesion->numero_alertas }}</td>
                        <td>{{ $sesion->estado }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
