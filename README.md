# DinoStar-Server
Servidor central del sistema DinoStar — recibe datos de venoclisis en tiempo real vía MQTT, los procesa y los muestra en un dashboard web para el personal de enfermería.


# Guía de contribución 

Esta guía define cómo trabajar con Git en el proyecto para que todos
sigamos las mismas convenciones y el historial del repositorio sea
limpio y rastreable.

---

## Estructura de ramas

```
main        → solo código estable y probado. Nadie sube directo aquí.
Develop         → rama de integración. Aquí se hace merge cuando algo funciona.
feature/*   → donde cada quien desarrolla su tarea.
fix/*       → para corregir bugs específicos.
docs/*      → para cambios solo de documentación.
```

**Regla principal:** nunca hagas commits directo a `main` ni a `dev`.
Siempre trabaja en tu rama `feature/` y avisa cuando termines.

---


## Formato del nombre de la rama

```
tipo/descripcion-corta-con-guiones
```

| Tipo | Cuándo usarlo |
|---|---|
| `feature/` | Para desarrollar algo nuevo |
| `fix/` | Para corregir un bug |
| `docs/` | Para cambios solo de documentación |


## Formato de los commits

```
tipo(#numero-tarea): descripción corta en minúsculas
```

El `#numero-tarea` es el número de la tarea en GitHub Projects.
Lo encuentras en la tarjeta de tu tarea, por ejemplo `#5`.

| Tipo | Cuándo usarlo |
|---|---|
| `feat` | Agrega funcionalidad nueva |
| `fix` | Corrige un bug |
| `docs` | Cambia solo documentación |
| `chore` | Configuración, dependencias, archivos de proyecto |
| `refactor` | Reorganiza código sin cambiar funcionalidad |
| `test` | Agrega o modifica pruebas |

**Ejemplos correctos:**
```
feat(#3): agregar dockerfile del backend con php 8.3
chore(#4): configurar mosquitto en puerto 1883
docs(#1): agregar instrucciones de instalacion al readme
fix(#7): corregir conexion de laravel con postgresql
feat(#9): crear migracion de tabla pacientes
```



*DinoStar · Gestión de Proyectos de Software · ITM 2026*
