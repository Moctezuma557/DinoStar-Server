# ==============================================
# DinoStar REX V1 - Frontend React con Vite
# ==============================================

FROM node:22-alpine

WORKDIR /app

# Copia los archivos de dependencias
COPY package.json package-lock.json ./

# Instala dependencias
RUN npm install

# Copia el resto del proyecto
COPY . .

EXPOSE 5173

CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0"]