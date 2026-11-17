<p align="center"><img src="public/brand/mark.svg" alt="Warren" width="84" style="color:#c4521b"></p>

# Warren

[English](README.md) · **Português**

Um painel de código aberto para o RabbitMQ, mais visual que o painel padrão. Em vez de tabelas, desenha o seu broker como um
diagrama ao vivo: **produtores → exchanges → filas → workers**, com cada bind e cada routing key escritos por inteiro, um
**painel** com gráficos de como as filas se moveram nas últimas horas e uma tela de **insights** que responde o que importa
para o negócio: quem está sobrecarregando as filas, quanto tempo leva cada tipo de trabalho e o que precisa de atenção agora.
Acompanha vários RabbitMQ ao mesmo tempo: cada um é uma **conexão** cadastrada pela própria tela.

> Estado: inicial, mas funcional. Idioma padrão: português do Brasil (há também inglês, com `APP_LOCALE=en`).

## O que ele faz

- **Painel**: a tela de entrada. Totais de agora (aguardando, em processamento, consumidores, publicadas por segundo) com a tendência do período, e gráficos de mensagens nas filas, taxas de entrada e entrega, mensagens publicadas, filas mais cheias, quem mais publica e áreas de negócio. Escolha o período: 1 hora, 6 horas, 24 horas ou 3 dias.
- **Conexões**: cadastre, teste, edite e remova os RabbitMQ que o Warren acompanha. O seletor no menu lateral troca de conexão; cada uma tem sua escuta, seu histórico e suas instâncias marcadas. As senhas ficam cifradas no banco com a `APP_KEY`.
- **Tema claro e escuro**: segue o sistema até você escolher um pelo botão no rodapé do menu.
- **Filas**: cada fila desenhada como uma fila de verdade, com um pacote por mensagem na cor da instância que enviou, da chegada até o worker. Mostra quem está na frente de quem (inclusive o efeito da prioridade) e abre a mensagem ao clicar no pacote.
- **Fluxo**: quatro colunas (produtores, exchanges, filas, workers) ligadas pelos binds. Cada fila mostra quantas mensagens estão *aguardando* e quantas estão *sendo processadas* (entregues e ainda sem ack), com uma bolinha por mensagem, colorida pela instância. Cada worker mostra quantas está processando, de quantas filas lê e o prefetch.
- **Mover o fluxo**: arraste com o botão do meio do mouse (a rodinha apertada) em qualquer lugar, ou com o botão esquerdo pelo fundo.
- **Painéis especiais**: uma entrada no menu ("Visões do catálogo") para cada fila que, por desenho, não tem consumidor (resultados, erros), com as mensagens escritas em português simples (veja "Painéis especiais").
- **Insights**: resumo, alertas, ranking de instâncias, áreas de negócio, filas sob pressão e workers (detalhes abaixo).
- **Produtores são instâncias**: um cartão por cliente/tenant/serviço encontrado nas mensagens (veja "Identificando instâncias").
- **Lista de instâncias** (lateral): marque as que quer acompanhar e só o fluxo delas aparece, no Fluxo e nas Mensagens. Nenhuma marcada mostra tudo. A lista vem ordenada por quem tem mais mensagens em processamento, e a seleção fica salva no navegador.
- **Mensagens**: tudo que a escuta viu, com filtros e onde cada mensagem está agora (estimado). Clique numa para ver propriedades, headers e corpo.
- **Detalhes**: o botão de qualquer cartão abre a ficha completa: todos os binds com routing key e argumentos, quem consome, quem produz, argumentos e taxas.
- **Routing keys completas** nas ligações exchange → fila (os binds), sem cortar; várias chaves na mesma ligação ficam empilhadas. As chaves que cada produtor usa ao publicar (dezenas, uma por mensagem) aparecem ao passar o mouse no produtor, e sempre na ficha dele.
- **Workers são processos, não consumidores**: todos os consumidores de um mesmo `usuário@host` viram um cartão só, não importa quantos canais ou filas usem.
- **Catálogo de filas**: nomes e áreas de negócio no lugar dos nomes técnicos ("Exportação de pedidos · acme" em vez de `exportacao_pedidos_v1_acme_example_com`).
- **Login** com e-mail e senha (sessão, limite de tentativas, sem cadastro público).
- **Somente leitura**: só faz requisições `GET` à API de gerenciamento.
- **Endereço por tela**: `#/fluxo`, `#/insights`, `#/painel/erros-pdf`... recarregar ou compartilhar o link volta para a mesma tela.

## Instalação

### Docker (recomendado)

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan warren:user voce@exemplo.com   # o primeiro usuário (pede a senha)
```

Abra http://localhost:8080, entre e cadastre o primeiro RabbitMQ em **Conexões** (o botão "Testar conexão" diz se a API de
gerenciamento e a porta AMQP respondem antes de salvar). Isso sobe dois contêineres da mesma imagem: `app` (a tela) e `tap`
(a escuta e a coleta de métricas, veja abaixo). Uma `APP_KEY` é gerada na primeira subida e guardada no volume `data`, junto
com o banco SQLite; faça backup dela junto com o banco, porque sem ela as senhas das conexões não podem ser lidas.

Se os `RABBITMQ_*` estiverem preenchidos no `.env`, a primeira conexão é criada a partir deles na subida (`warren:broker --from-env`),
só quando ainda não existe nenhuma. Depois disso, as conexões vivem no banco e o `.env` não é mais lido para elas.

- Um broker rodando na sua máquina é acessível como `http://host.docker.internal:15672` (e host AMQP `host.docker.internal` na conexão).
- Troque a porta com `PORT=9000 docker compose up -d`.
- Atualize com `git pull && docker compose up -d --build`; as migrações rodam ao iniciar.
- Faça backup do volume `data` e mantenha-o em disco local: o modo WAL do SQLite não funciona em sistema de arquivos de rede.

> **Segurança:** a tela mostra o corpo das mensagens (com senhas, tokens e JWTs mascarados), por isso pede login (veja "Login" e o [SECURITY.md](SECURITY.md)). A porta é publicada só em `127.0.0.1`.
> Antes de expor para uma rede, sirva por HTTPS (o login envia a senha), defina `SESSION_SECURE_COOKIE=true` e, se usar proxy
> reverso, defina `WARREN_TRUSTED_PROXIES` para o limite de tentativas enxergar o IP real do cliente.

**Experimente sem um broker.** Este arquivo acrescenta um RabbitMQ descartável (com o rastreio ligado) e um gerador de tráfego que
publica para cinco clientes em exchanges `demo.*`, com um worker lento, para todas as partes do diagrama terem o que mostrar:

```bash
docker compose -f docker-compose.yml -f docker-compose.demo.yml up --build
```

Nunca use o arquivo de demonstração contra um broker real.

### Login

Não há cadastro público. Crie o primeiro usuário de um destes jeitos:

```bash
docker compose exec app php artisan warren:user voce@exemplo.com   # pede a senha
# ou, no .env, antes da primeira subida (criado só se ainda não existir, nunca sobrescrito):
WARREN_ADMIN_EMAIL=voce@exemplo.com
WARREN_ADMIN_PASSWORD=pelo-menos-8-caracteres
```

O mesmo comando redefine uma senha esquecida. Depois de 5 senhas erradas para o mesmo e-mail e IP, o login fica bloqueado por um minuto.
Se algo na frente do Warren já autentica (VPN, proxy com SSO), dá para desligar o login com `WARREN_AUTH=false`.

### Sem Docker

```bash
cp .env.example .env
php artisan key:generate

composer install
npm install && npm run build
php artisan migrate
php artisan serve
php artisan warren:user voce@exemplo.com
```

Abra http://localhost:8000 (PHP 8.3+ com as extensões `sockets` e `pcntl`). Em desenvolvimento, rode `npm run dev` junto com `php artisan serve`.

Com Docker a escuta já está rodando. Sem ele, produtores, mensagens e os gráficos do Painel precisam dela, um segundo processo
de longa duração:

```bash
php artisan warren:tap     # em produção, mantenha sob Supervisor/systemd
```

Um único `warren:tap` atende todas as conexões: escuta cada uma (reconectando sozinha depois de uma falha, sem afetar as outras),
tira uma leitura dos totais a cada 30 segundos para o Painel e guarda 3 dias de histórico. Conexões criadas, editadas ou
removidas na tela entram em poucos segundos, sem reiniciar. Sem a escuta, o Fluxo ainda mostra exchanges, filas e workers, e
avisa como iniciá-la. Para só monitorar um broker, sem copiar mensagens, desligue a escuta naquela conexão.

## Permissões no broker

O usuário precisa de acesso à API de gerenciamento. Com a tag `monitoring`, `/api/consumers` lista todos os consumidores de uma vez.
Só com `management`, lista apenas os do próprio usuário; então o Warren lê o detalhe de cada fila (em paralelo e com cache de
`WARREN_CONSUMER_DETAILS_TTL` segundos). Funciona nos dois casos; `monitoring` pesa menos no broker. Para a escuta, o usuário
precisa de permissão de configurar, escrever e ler no vhost.

## Como a escuta enxerga as mensagens

A API de gerenciamento não mostra mensagens sem consumi-las. A escuta conecta por AMQP (porta 5672) e cria duas filas temporárias,
exclusivas e com auto-delete (limitadas a 5000 mensagens): uma ligada às suas exchanges, para o broker mandar uma cópia de tudo que
é publicado; outra ligada à exchange de rastreio. **Suas filas nunca são tocadas**, e as temporárias somem quando a escuta desconecta.

- A exchange padrão não aceita binds, então o que é publicado direto numa fila (respostas por `reply_to`, por exemplo) só aparece com o rastreio do broker ligado: `rabbitmqctl trace_on`. Com ele você também vê o horário de entrega de cada mensagem e a **espera média** nos insights. O rastreio tem custo no broker; desligue com `rabbitmqctl trace_off`.
- Sem o rastreio, "está na fila" é estimativa: as N mensagens mais recentes roteadas para ela, sendo N a profundidade atual.
- Só aparecem mensagens publicadas depois que a escuta subiu. As últimas `WARREN_HISTORY_LIMIT` (3000) ficam no banco.

## Identificando instâncias

"Instância" é o que você usa para agrupar produtores. O RabbitMQ não tem esse conceito, então você diz onde achá-la
(no `.env`; vale a primeira regra que acertar): caminhos no corpo JSON, nomes de header e regex sobre `exchange|routing_key` com um grupo `(?<instance>...)`.

```dotenv
WARREN_INSTANCE_PATHS=tenant,data.tenantId
WARREN_INSTANCE_HEADERS=x-tenant
WARREN_INSTANCE_REGEXES='["/\\|app\\.events\\.(?<instance>[^.]+)/"]'
# opcional: junta grafias diferentes da mesma instância (pares [regex, substituição])
WARREN_INSTANCE_NORMALIZE='[["/_empresa_com_br$/", ".empresa.com.br"]]'
```

Sem a normalização, o mesmo cliente escrito como `acme_empresa_com_br` na routing key e `acme.empresa.com.br` no corpo apareceria como duas instâncias no ranking.

## Catálogo de filas

Um broker só conhece nomes técnicos. O catálogo é um arquivo JSON que diz o que cada fila significa para o negócio e as agrupa em
áreas. Copie `catalogo/exemplo.json` para `catalogo/catalogo.json` (esse arquivo não vai para o git nem para a imagem Docker, porque
costuma ter nomes internos) e ajuste. As regras são regex testadas em ordem; vale a primeira que acertar. Grupos nomeados viram
variáveis no nome:

```json
{
    "categorias": { "pedidos": { "nome": "Pedidos", "descricao": "Depois do fechamento.", "cor": "#56b6f2" } },
    "instancias": { "remover": ["_minhaempresa_com_br$"] },
    "filas": [
        { "padrao": "^pedidos\\.processar\\.falhas$", "nome": "Pedidos · falhas", "categoria": "pedidos", "papel": "falhas" },
        { "padrao": "^nfe_(?<instancia>.+)$", "nome": "Nota fiscal · {instancia}", "categoria": "pedidos" }
    ]
}
```

O `papel` é `principal`, `espera` (fila onde a mensagem aguarda nova tentativa), `falhas` (DLQ) ou `passiva` (fila que, por desenho, não
tem consumidor: não gera o alerta "ninguém consumindo"). Sem regra, o papel é deduzido do nome (`...aguardando`, `...delay`,
`...dlq`, `...falhas`). Uma categoria com `"generica": true` (uma fila catch-all, infraestrutura compartilhada) nunca conta como a
"área principal" de uma instância, senão todas pareceriam ser dessa área. O arquivo é relido quando muda, sem reiniciar. No Docker o diretório
`catalogo/` é montado; fora dele, use `WARREN_CATALOG=/caminho/catalogo.json`.

### Nomes de instâncias e de workers

O broker só conhece chaves técnicas (`acme.empresa.com.br`, `app-pdf@10.0.0.2`). O catálogo dá nomes que as pessoas reconhecem:

```json
{
    "instancias": {
        "remover": ["_empresa_com_br$", "\\.empresa\\.com\\.br$"],
        "nomes": { "piedpiper": "Pied Piper" }
    },
    "workers": [
        { "padrao": "^app-pdf@(?<host>.+)$", "nome": "Gerador de PDF · {host}", "servico": "Gerador de PDF",
          "descricao": "Converte HTML em PDF." }
    ]
}
```

- **Instâncias**: o que sobra depois de `remover` é procurado em `nomes` (sem diferenciar maiúsculas); sem entrada, vira uma palavra com inicial maiúscula (`acme` → "Acme", `meu_cliente` → "Meu Cliente", uma palavra de até 3 letras vira sigla: `fip` → "FIP"). A mesma instância escrita de dois jeitos (`acme.empresa.com.br` no corpo, `acme_empresa_com_br` na routing key) tem um nome só. O nome aparece nos produtores, na lista lateral, nas mensagens, nos insights e nos painéis; a chave técnica fica na dica ao passar o mouse e a busca acha pelos dois.
- **Workers**: um cartão por **processo** (`usuário@host`), não importa quantas filas ou instâncias ele leia: 3 processos do mesmo serviço são 3 cartões, cada um ligado a todas as filas que consome. Os cartões ficam agrupados por serviço. Clicar no cartão abre a ficha: IP, usuário, nós do RabbitMQ, cada conexão (origem `ip:porta`, nó e consumidores) e as filas que ele lê.
- Passar o mouse sobre um cartão destaca só as conexões dele e apaga o resto.

## Painéis especiais

Algumas filas não têm consumidor de propósito: filas de **resultados** (avisos de que um trabalho terminou) e de **erros** (DLQ que só
registra). Para elas o catálogo pode definir um **painel**, que vira uma entrada no menu com as mensagens traduzidas para quem não é técnico:
um status (concluído, erro, aviso), uma frase, os dados principais e **a hora em que a mensagem foi colocada na fila**. Marque
essas filas com `"papel": "passiva"` (resultados) ou `"papel": "falhas"` (erros) para não gerarem o alerta "ninguém consumindo".

```json
{
    "vocabulario": { "pdf.gerar": "geração de PDF", "prova_padrao": "nota fiscal" },
    "paineis": [{
        "id": "resultados", "titulo": "Resultados dos pedidos", "tipo": "resultados",
        "descricao": "O que o serviço de pedidos avisa ao terminar. Esta fila não tem consumidor de propósito.",
        "filas": ["^pedidos\\.resultados$"],
        "regras": [
            { "chave": "^pedidos\\.(?<loja>[^.]+)\\.(?<acao>.+)\\.success$", "status": "sucesso",
              "titulo": "{acao}", "texto": "Terminou com sucesso: {acao} da loja {loja}.",
              "campos": { "Pedido": "corpo:pedido.id" } },
            { "chave": "\\.error$", "quando": { "corpo:tentarEm": ">0" }, "status": "erro",
              "texto": "Deu erro, vai tentar de novo em {corpo:tentarEm|duracao_ms}." }
        ]
    }]
}
```

- `filas`: regex dos nomes das filas do painel. `regras`: testadas em ordem, vale a primeira que acertar (`chave` é um regex sobre a routing key; `quando` compara campos: `">0"`, `"<=5"`, `"!x"` ou um valor).
- No texto: `{grupo}` vem do regex da chave; `{corpo:a.b}` do corpo (JSON dentro de texto JSON é aberto sozinho); `{cab:nome}` dos headers; `{fila}`, `{instancia}`. Modificadores: `|ultimo` (o que vem depois do último ponto), `|duracao_ms` (milissegundos em "30 s") e `|humanizar`. Plural: `{cab:tentativas|tentativa|tentativas}` escolhe a palavra pelo número (só 1 fica no singular); com três formas, a primeira vale para zero: `{corpo:total|nenhum item|# item|# itens}`, onde `#` é o número.
- `vocabulario` troca códigos por palavras: `{acao}` vira "geração de PDF". Sem vocabulário, `pdf.gerar` aparece como "pdf gerar".
- Sem regra que acerte, a mensagem ainda aparece, com uma descrição genérica e o status deduzido do fim da routing key (`success`, `error`...).

**Segredos ficam escondidos.** Valores de campos chamados `token`, `senha`, `password`, `secret`, `authorization`, `jwt`..., o `token=` de qualquer URL e qualquer JWT são trocados por `••••••` antes de chegar à tela. Mensagens entre serviços costumam levar URLs de callback com token assinado.

**De onde vêm as mensagens.** Do que a **escuta** viu ser publicado, nunca de dentro da fila. Em fila *quorum*, espiar uma mensagem (como o painel do RabbitMQ faz) conta como uma entrega, e o limite de entregas (20 por padrão no RabbitMQ 4) faz o broker **descartar a mensagem**, o que numa DLQ apagaria as próprias evidências. Por isso:

- as mensagens de uma fila alimentada por uma exchange (resultados) aparecem assim que são publicadas, com a hora exata;
- as de uma fila alimentada pela exchange padrão (como uma DLQ publicada direto) só aparecem com o rastreio ligado (`rabbitmqctl trace_on`);
- o que já estava na fila antes da escuta subir não aparece: o painel mostra quantas mensagens a fila tem e avisa que são anteriores.

## Insights

A tela **Insights** responde:

| Pergunta | Onde |
|---|---|
| Algo precisa de atenção? | **Alertas**: fila com mensagens e sem consumidor; mensagens esperando e nada saindo; fila que levaria mais de 5 min para esvaziar; mensagens em falha (DLQ); mensagens aguardando nova tentativa; mensagens publicadas que não chegaram a nenhuma fila. |
| Quem mais envia e mais pesa nas filas? | **Ranking de instâncias**, por mensagens pendentes (aguardando + processando), com a parcela do acúmulo, o ritmo por minuto e a área principal. Uma instância que concentra metade do acúmulo e o dobro da segunda recebe o aviso "domina o acúmulo". |
| Como está cada tipo de trabalho? | **Áreas de negócio** (do catálogo): filas, aguardando, processando, entrada, tempo médio, tempo para esvaziar, retentativas e falhas. |
| Quais filas estão sob pressão? | **Filas**, com mais mensagens esperando primeiro. |
| Os workers dão conta? | **Workers**: processando, capacidade (consumidores × prefetch), ocupação e tempo médio. |

**Os tempos são estimativas**, e a tela diz isso. O broker não informa quanto cada mensagem leva (o rastreio registra publicações e
entregas, não os acks), então:

- **Tempo médio de processamento** = mensagens entregues e sem ack ÷ acks por segundo (Lei de Little). Vale com o fluxo estável; some quando quase nada é concluído, em vez de mostrar um número sem sentido.
- **Para esvaziar** = mensagens aguardando ÷ entregas por segundo. `∞` quando há mensagens e nada sai.
- **Espera média** = da publicação até a entrega, medida pelo rastreio nos últimos 10 minutos. Exige `rabbitmqctl trace_on`.
- O "processando" de cada **worker** e de cada **instância** é proporcional: o broker só informa "sem ack" por fila.

## Testes

```bash
php artisan test   # backend (PHPUnit)
npm test           # front-end (Vitest)
npm run typecheck  # vue-tsc
scripts/smoke.sh   # contra uma instância em execução (a CI usa a pilha de demonstração)
```

## Estrutura

| Caminho | Função |
|---|---|
| `app/Rabbit/ManagementClient.php` | cliente somente leitura da API de gerenciamento |
| `app/Rabbit/ConsumerCollector.php` | lista completa de consumidores, com o contorno da tag `management` |
| `app/Rabbit/WorkerGrouper.php` | consumidores → workers, estimativa de processamento |
| `app/Rabbit/TopologyService.php` | um retrato do broker, com cache |
| `app/Rabbit/Catalog.php` | nomes e áreas de negócio das filas, vocabulário e painéis |
| `app/Rabbit/MessageFormatter.php`, `Redactor.php`, `MessagePath.php` | mensagem → frase em português, sem segredos |
| `app/Http/Controllers/PanelController.php` | mensagens de um painel especial |
| `app/Rabbit/MessageTap.php` | o ouvinte AMQP de uma conexão |
| `app/Warren/Supervisor.php`, `MetricSampler.php` | `warren:tap`: uma escuta por conexão e as leituras do Painel |
| `app/Http/Controllers/BrokerController.php`, `MetricsController.php` | conexões (cadastro, teste, saúde) e séries do Painel |
| `app/Rabbit/Router.php` | para quais filas uma mensagem vai (direct, topic, fanout, encadeamento) |
| `app/Rabbit/InstanceResolver.php` | a que instância uma mensagem pertence |
| `app/Rabbit/MessageRecorder.php` | grava mensagens e entregas |
| `app/Http/Controllers/Auth/LoginController.php`, `app/Console/Commands/UserCommand.php` | login e usuários |
| `app/Console/Commands/DemoCommand.php` | `warren:demo`, gerador de tráfego |
| `resources/js/lib/estimate.ts` | o que há em cada fila, processamento por instância |
| `resources/js/pages/Workspace.vue` | a casca: menu lateral, seletor de conexão, barra do topo e as telas |
| `resources/js/Components/Dashboard.vue`, `Components/charts/` | Painel e gráficos (SVG próprio, sem biblioteca) |
| `resources/js/Components/Connections.vue`, `resources/js/lib/brokers.ts` | tela de conexões; estado e API da conexão aberta |
| `resources/js/lib/section.ts`, `resources/js/lib/theme.ts` | endereço de cada tela (`#/...`); tema claro/escuro |
| `resources/css/base.css`, `resources/css/app.css` | cores (claro e escuro) e layout; estilos do fluxo, detalhes e painéis |
| `resources/js/Components/Panel.vue`, `resources/js/lib/pan.ts` | painéis especiais; arrastar o fluxo |
| `resources/js/lib/insights.ts` | funções puras dos insights (tempos, ranking, alertas) |
| `resources/js/lib/layout.ts` | função pura: topologia → nós e arestas do diagrama |
| `resources/js/Components/` | `Diagram`, `Insights`, `Details`, `InstanceList`, `MessageList` |
| `resources/js/lib/i18n.ts`, `lang/` | textos em português e inglês |
| `Dockerfile`, `docker-compose*.yml` | imagem (FrankenPHP, usuário não-root), web + escuta, demonstração |

## Roteiro

- Alertas por e-mail/webhook a partir das séries do Painel.
- Arrastar cartões e isolar o fluxo de uma única exchange, fila ou worker.
- Animação das mensagens chegando.
- WebSocket (Laravel Reverb) em vez de polling.
- Papéis além de "logado" (por exemplo, quem pode editar conexões).

## Contribuindo

Issues e pull requests são bem-vindos. Rode os testes e a checagem de tipos antes de abrir um PR (`vendor/bin/pint` formata o PHP).

## Licença

[MIT](LICENSE)
