import sys
import paho.mqtt.client as mqtt

# Configuración del broker (Ejecutándose localmente vía Docker)
BROKER_HOST = "localhost"
BROKER_PORT = 1883
TOPIC_TEST = "dinostar/test"

def on_connect(client, userdata, flags, rc, properties=None):
    if rc == 0:
        print("[SUCCESS] Conexión establecida exitosamente con el broker Mosquitto.")
        # Publicamos un mensaje de prueba para verificar conectividad
        client.publish(TOPIC_TEST, payload="Simulador conectado correctamente")
    else:
        print(f"[ERROR] Falló la conexión al broker. Código de retorno: {rc}")

def main():
    # Inicializar cliente paho-mqtt (compatible con v2.x y v1.x)
    try:
        client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
    except AttributeError:
        client = mqtt.Client()  # Fallback para versiones anteriores de paho-mqtt

    client.on_connect = on_connect

    try:
        print(f"Intentando conectar a Mosquitto en {BROKER_HOST}:{BROKER_PORT}...")
        client.connect(BROKER_HOST, BROKER_PORT, keepalive=60)
        
        # Ejecutamos una sola iteración del loop para validar conexión y salir
        client.loop_start()
        import time
        time.sleep(2)
        client.loop_stop()
        client.disconnect()
    except Exception as e:
        print(f"[ERROR] No se pudo establecer la conexión: {e}")
        sys.exit(1)

if __name__ == "__main__":
    main()