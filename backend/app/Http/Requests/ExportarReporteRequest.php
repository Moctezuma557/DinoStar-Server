<?php

namespace App\Http\Requests;

/** La exportación PDF acepta exactamente los mismos filtros que los reportes JSON. */
class ExportarReporteRequest extends FiltrosReporteRequest {}
