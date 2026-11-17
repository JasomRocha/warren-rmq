# Linguagem visual do Warren

Decisões que valem para toda tela nova. O objetivo é parecer uma ferramenta de operação, não um modelo genérico de painel.

## Cor
- Interface neutra (tons de cinza levemente quentes). A única cor de ação é a tinta: texto quase preto no claro, quase branco no escuro. Botão primário, item ativo do menu e período escolhido usam essa tinta.
- Cor é reservada para **dados e estado**: produtor, exchange, fila e worker têm uma cor cada (`--c-*`), e verde/âmbar/vermelho só indicam situação.
- O laranja (`--brand`) aparece só no coelho do logo.
- O azul de foco (`--focus`) aparece só no anel de foco do teclado.
- Nada de gradiente, brilho ou roxo decorativo.

## Tipografia
- IBM Plex Sans para texto e IBM Plex Mono para valores técnicos (eixos, nomes de fila, horários). As fontes vão no pacote, sem chamada externa.
- Números sempre com `tabular-nums`, para colunas e indicadores não "dançarem".
- Rótulos em caixa normal. Caixa alta com espaçamento largo não é usada.

## Superfícies
- Blocos têm borda de 1 px, raio de 8 px e nenhuma sombra. Sombra só no que flutua (dica do gráfico, gaveta do menu).
- Nada de card dentro de card. Nada de borda colorida à esquerda para indicar status: o status vai escrito ("Crítico", "Acumulando") ou num ícone com rótulo.
- Bolinha de status só ao lado de um texto que diz o que ela significa.

## Hierarquia e ritmo
- Cada tela tem um ponto focal. No Painel são a faixa de indicadores e o gráfico de entrada e processamento. O resto é detalhe, em blocos menores.
- Espaço apertado dentro de um grupo e folgado entre grupos. Os controles têm 32 px de altura.
- Textos de estado vazio dizem o que fazer a seguir, por exemplo "O coletor grava uma leitura a cada 30 segundos enquanto o `warren:tap` está rodando", e não "Sem dados".

## Métricas
- O Painel só conta filas de trabalho: as que têm consumidor e papel `principal` no catálogo. Filas de resultados, de espera e de falhas ficam de fora dos totais e dos gráficos.
- A espera estimada usa a Lei de Little: mensagens na fila divididas pela taxa de processamento. Quando há mensagens e nada sai, a espera é ∞.
