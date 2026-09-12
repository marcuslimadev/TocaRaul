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

## Build de publicação

Com `JAVA_HOME` apontando para o JDK 25 do Android Studio, execute da raiz:

```powershell
.\scripts\build-android.ps1 -Unsigned
```

O script configura o diretório de sockets Java dentro de `tmp`, resolvendo a falha
`Unable to establish loopback connection` neste Windows, e executa bundle e lint.
O modo `-Unsigned` serve apenas para validação: o AAB gerado não pode ser enviado à loja.

Para assinar, configure no ambiente `TOCARAUL_UPLOAD_STORE_FILE`,
`TOCARAUL_UPLOAD_STORE_PASSWORD`, `TOCARAUL_UPLOAD_KEY_ALIAS` e
`TOCARAUL_UPLOAD_KEY_PASSWORD`, e execute o script sem `-Unsigned`.
Use a chave de upload apropriada ao cadastro do app e mantenha uma cópia segura.
Não grave senhas no código ou na linha de comando. Arquivos de chave estão ignorados pelo Git.

Saída: `Android/app/build/outputs/bundle/release/app-release.aab`.
Relatório: `Android/app/build/reports/lint-results-release.html`.

Testes de recuperação do player, com um emulador conectado e o mesmo ajuste de
`JAVA_TOOL_OPTIONS` do script:

```powershell
cd Android
.\gradlew.bat :app:connectedDebugAndroidTest -PtocaraulE2e=true --no-daemon
```

## Milestones históricos

1. Compilar no Android Studio.
2. Rodar no emulador Android TV 1080p.
3. Implementar ativacao real da TV.
4. Adicionar heartbeat.
5. Buscar estado da jukebox em `/api/device/state`.
6. Mostrar pedidos e dedicatórias vindos do backend.
