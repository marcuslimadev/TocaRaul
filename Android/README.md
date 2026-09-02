# TocaRaul TV

App Android TV nativo do TocaRaul.

## Papel do app

- Roda na TV ou TV Box do bar.
- Exibe tela de ativacao, player, fila, QR Code e dedicatórias.
- Conecta ao backend publico por HTTPS: `https://tocaraul.lojadaesquina.store`.
- Nao conecta diretamente ao MySQL remoto.
- Nao guarda credenciais de banco, Pagar.me ou streaming.

## Arquitetura

```text
Cliente no celular -> Backend publico -> MySQL remoto
Android TV --------^
```

O MySQL remoto entra desde o inicio, mas sempre atras da API do backend. Isso evita expor credenciais dentro do APK.

## Proximo milestone

1. Compilar no Android Studio.
2. Rodar no emulador Android TV 1080p.
3. Implementar ativacao real da TV.
4. Adicionar heartbeat.
5. Buscar estado da jukebox em `/api/device/state`.
6. Mostrar pedidos e dedicatórias vindos do backend.
