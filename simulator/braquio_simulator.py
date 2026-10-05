import argparse
import json
import time
import random
import threading
import paho.mqtt.client as mqtt
from datetime import datetime

# Configuración MQTT por defecto
DEFAULT_BROKER = "localhost"
DEFAULT_PORT = 1883
DEFAULT_TOPIC = "hospital/braquio/telemetria"

# Constantes médicas
VOLUMENES_INICIALES = [100.0, 250.0, 500.0]
MODOS_GOTEO = ["NORMAL_GOTEO", "MICRO_GOTEO"]  
FACTOR_GOTEO = {
    "NORMAL_GOTEO": 20,  # 20 gotas = 1 mL  →  1 gota = 0.05 mL
    "MICRO_GOTEO": 60    # 60 gotas = 1 mL  →  1 gota = 0.0167 mL
}

class DispositivoREX:
    def __init__(self, paciente_id, modo=None, volumen=None):
        self.pacienteId = paciente_id
        self.volRestante = volumen if volumen is not None else random.choice(VOLUMENES_INICIALES)
        self.modo = modo if modo is not None else random.choice(MODOS_GOTEO)
        self.modo_fijo = modo
        self.ultima_actualizacion = time.time()

    def generar_lectura(self, escenario="aleatorio"):
        hora = time.time()
        delta_tiempo_min = (hora - self.ultima_actualizacion) / 60.0
        self.ultima_actualizacion = hora

        # 15% de probabilidad de generar una anomalía médica
        es_anomalia = random.random() < 0.15
        
        if escenario != "aleatorio":
            self.gotasPorMin = {
                "normal": 32.5, "lento": 12.3, "rapido": 85.1,
                "fin-bolsa": 32.5, "combinado": 12.3,
            }[escenario]
        elif es_anomalia:
            # Umbrales de DataPacketService: lento <15, rápido >80.
            self.gotasPorMin = round(random.choice([random.uniform(10, 14.9), random.uniform(80.1, 100)]), 1)
        else:
            # Condición normal
            self.gotasPorMin = round(random.uniform(20, 60), 1)

        # Calcular consumo de volumen real basado en el tiempo transcurrido
        ml_por_minuto = self.gotasPorMin / FACTOR_GOTEO[self.modo]
        volumen_consumido = ml_por_minuto * delta_tiempo_min
        
        self.volRestante -= volumen_consumido

        # Simular cambio de bolsa si se acaba el suero
        if self.volRestante <= 0:
            print(f"Paciente {self.pacienteId}: Bolsa de suero vacía. Reemplazando bolsa...")

            self.volRestante = random.choice(VOLUMENES_INICIALES)
            self.modo = self.modo_fijo or random.choice(MODOS_GOTEO)

        if escenario in ("fin-bolsa", "combinado"):
            self.volRestante = 45.2

        ml_por_minuto = self.gotasPorMin / FACTOR_GOTEO[self.modo]

        # Calcular tiempo restante en minutos
        if ml_por_minuto > 0:
            tiempo_restante_min = int(self.volRestante / ml_por_minuto)
        else:
            tiempo_restante_min = 0

        return {
            "pacienteId": self.pacienteId,
            "gotasPorMin": self.gotasPorMin,
            "tiempoRestante": tiempo_restante_min,
            "volRestante": round(self.volRestante, 1),
            "modo": self.modo,
            "timestamp": int(hora * 1000)
        }

def on_connect(client, userdata, flags, rc, properties=None):
    if rc == 0:
        print(f"Conectado exitosamente al broker MQTT.")
        userdata.set()
    else:
        print(f"Error de conexión al broker MQTT. Código: {rc}")

def iniciar_simulacion():
    parser = argparse.ArgumentParser(description="Simulador de Nodo Braquio para dispositivos REX")
    parser.add_argument("-p", "--pacientes", type=int, choices=range(2, 11), default=8, 
                        help="Número de pacientes a simular (2-10). Por defecto: 8")
    parser.add_argument("-c", "--ciclo", type=int, default=5, 
                        help="Tiempo en segundos entre cada ciclo de envío. Por defecto: 5")
    parser.add_argument("--broker", default=DEFAULT_BROKER)
    parser.add_argument("--port", type=int, default=DEFAULT_PORT)
    parser.add_argument("--topic", default=DEFAULT_TOPIC)
    parser.add_argument("--ciclos", type=int, default=0,
                        help="Número de lotes a publicar; 0 mantiene la simulación continua")
    parser.add_argument("--demo", action="store_true",
                        help="Usar modos y volúmenes compatibles con DemoSeeder: cama-01 NORMAL 500, cama-02 MICRO 250")
    parser.add_argument("--escenario", default="aleatorio",
                        choices=["aleatorio", "normal", "lento", "rapido", "fin-bolsa", "combinado"])
    args = parser.parse_args()
    if args.ciclo <= 0 or args.ciclos < 0 or not 1 <= args.port <= 65535:
        parser.error("--ciclo debe ser positivo, --ciclos no negativo y --port entre 1 y 65535")
    if not args.topic or any(char in args.topic for char in "#+\0"):
        parser.error("--topic debe ser un tópico de publicación sin comodines")

    # Inicializar camas / dispositivos
    dispositivos = [DispositivoREX(f"cama-{str(i+1).zfill(2)}") for i in range(args.pacientes)]
    if args.demo:
        dispositivos[0] = DispositivoREX("cama-01", "NORMAL_GOTEO", 500.0)
        dispositivos[1] = DispositivoREX("cama-02", "MICRO_GOTEO", 250.0)
    
    # Configurar cliente MQTT con la API V2
    conectado = threading.Event()
    client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, userdata=conectado)
    try:
        client.on_connect = on_connect
        print(f"Conectando al broker {args.broker}:{args.port}...")
        client.connect(args.broker, args.port, 60)
        client.loop_start()
        if not conectado.wait(10):
            raise RuntimeError("El broker no confirmó la conexión MQTT")
    except Exception as e:
        client.disconnect()
        client.loop_stop()
        parser.exit(1, f"No se pudo conectar al broker MQTT: {e}\n")

    print(f"Iniciando simulación Nodo Braquio con {args.pacientes} pacientes.")
    print(f"Ciclo de actualización: {args.ciclo} segundos.")
    print("-" * 50)

    try:
        enviados = 0
        while args.ciclos == 0 or enviados < args.ciclos:
            payload_braquio = [dispositivo.generar_lectura(args.escenario) for dispositivo in dispositivos]

            # Empaquetar el arreglo de DataPackets a JSON
            json_data = json.dumps(payload_braquio, indent=2)
            
            # Publicar en MQTT si hay conexión disponible
            if not client.is_connected():
                raise RuntimeError("Se perdió la conexión MQTT")
            envio = client.publish(args.topic, json_data)
            envio.wait_for_publish(timeout=10)
            if not envio.is_published():
                raise RuntimeError("El lote no pudo publicarse")
            enviados += 1
            
            print(f"[{datetime.now().strftime('%H:%M:%S')}] DataPackets enviados:")
            print(json_data)
            print("-" * 50)
            
            if args.ciclos == 0 or enviados < args.ciclos:
                time.sleep(args.ciclo)
            
    except KeyboardInterrupt:
        print("\n Simulación detenida por el usuario.")
    except (RuntimeError, OSError) as e:
        parser.exit(1, f"Error durante la simulación: {e}\n")
    finally:
        client.disconnect()
        client.loop_stop()

if __name__ == "__main__":
    iniciar_simulacion()
