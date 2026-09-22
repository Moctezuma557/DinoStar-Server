import argparse
import json
import time
import random
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
    def __init__(self, paciente_id):
        self.pacienteId = paciente_id
        self.volRestante = random.choice(VOLUMENES_INICIALES)
        self.modo = random.choice(MODOS_GOTEO)
        self.ultima_actualizacion = time.time()

    def generar_lectura(self):
        hora = time.time()
        delta_tiempo_min = (hora - self.ultima_actualizacion) / 60.0
        self.ultima_actualizacion = hora

        # 15% de probabilidad de generar una anomalía médica
        es_anomalia = random.random() < 0.15
        
        if es_anomalia:
            # Anomalía: Goteo muy lento (10-19) o muy rápido (61-80)
            self.gotasPorMin = round(random.choice([random.uniform(10, 19), random.uniform(61, 80)]), 1)
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
            self.modo = random.choice(MODOS_GOTEO)

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
    else:
        print(f"Error de conexión al broker MQTT. Código: {rc}")

def iniciar_simulacion():
    parser = argparse.ArgumentParser(description="Simulador de Nodo Braquio para dispositivos REX")
    parser.add_argument("-p", "--pacientes", type=int, choices=range(2, 11), default=8, 
                        help="Número de pacientes a simular (2-10). Por defecto: 8")
    parser.add_argument("-c", "--ciclo", type=int, default=5, 
                        help="Tiempo en segundos entre cada ciclo de envío. Por defecto: 5")
    args = parser.parse_args()

    # Inicializar camas / dispositivos
    dispositivos = [DispositivoREX(f"cama-{str(i+1).zfill(2)}") for i in range(args.pacientes)]
    
    # Configurar cliente MQTT con la API V2
    client = None
    try:
        client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
        client.on_connect = on_connect
        print(f"Conectando al broker {DEFAULT_BROKER}:{DEFAULT_PORT}...")
        client.connect(DEFAULT_BROKER, DEFAULT_PORT, 60)
        client.loop_start()
    except Exception as e:
        print(f"Advertencia: No se pudo conectar al broker MQTT ({e}). Ejecutando solo en consola local.")
        client = None

    print(f"Iniciando simulación Nodo Braquio con {args.pacientes} pacientes.")
    print(f"Ciclo de actualización: {args.ciclo} segundos.")
    print("-" * 50)

    try:
        while True:
            payload_braquio = [dispositivo.generar_lectura() for dispositivo in dispositivos]

            # Empaquetar el arreglo de DataPackets a JSON
            json_data = json.dumps(payload_braquio, indent=2)
            
            # Publicar en MQTT si hay conexión disponible
            if client and client.is_connected():
                client.publish(DEFAULT_TOPIC, json_data)
            
            print(f"[{datetime.now().strftime('%H:%M:%S')}] DataPackets enviados:")
            print(json_data)
            print("-" * 50)
            
            time.sleep(args.ciclo)
            
    except KeyboardInterrupt:
        print("\n Simulación detenida por el usuario.")
        if client:
            client.loop_stop()
            client.disconnect()

if __name__ == "__main__":
    iniciar_simulacion()