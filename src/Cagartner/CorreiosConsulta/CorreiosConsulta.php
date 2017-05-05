<?php namespace Cagartner\CorreiosConsulta;

use Cagartner\CorreiosConsulta\Curl;
use PhpQuery\PhpQuery as phpQuery;

class CorreiosConsulta
{

    public function frete($dados, $options = array())
    {
        $endpoint = 'http://ws.correios.com.br/calculador/CalcPrecoPrazo.asmx?WSDL';

        $tipos = array(
            'sedex'          => '04014',
            'sedex_a_cobrar' => '40045',
            'sedex_10'       => '40215',
            'sedex_hoje'     => '40290',
            'pac'            => '04510',
            'pac_contrato'   => '41068',
            'sedex_contrato' => '40096',
            'esedex'         => '81019',
        );

        $formatos = array(
            'caixa'    => 1,
            'rolo'     => 2,
            'envelope' => 3,
        );

        $dados['tipo']    = $tipos[$dados['tipo']];
        $dados['formato'] = $formatos[$dados['formato']];
        /* dados[tipo]
        04014 SEDEX Varejo
        40045 SEDEX a Cobrar Varejo
        40215 SEDEX 10 Varejo
        40290 SEDEX Hoje Varejo
        04510 PAC Varejo
        */

        /*
        1 – Formato caixa/pacote
        2 – Formato rolo/prisma
        3 - Envelope
        */
        $dados['cep_destino'] = preg_replace("/[^0-9]/", '', $dados['cep_destino']);
        $dados['cep_origem']  = preg_replace("/[^0-9]/", '', $dados['cep_origem']);

        $options = array_merge(array(
            'trace'              => true,
            'exceptions'         => true,
            'compression'        => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP,
            'connection_timeout' => 1000
        ), $options);
        
        $soap = new \SoapClient($endpoint, $options);

        $params = array(
            'nCdEmpresa'          => (isset($dados['empresa']) ? $dados['empresa'] : ''),
            'sDsSenha'            => (isset($dados['senha']) ? $dados['senha'] : ''),
            'nCdServico'          => $dados['tipo'],
            'sCepOrigem'          => $dados['cep_origem'],
            'sCepDestino'         => $dados['cep_destino'],
            'nVlPeso'             => $dados['peso'],
            'nCdFormato'          => $dados['formato'],
            'nVlComprimento'      => $dados['comprimento'],
            'nVlAltura'           => $dados['altura'],
            'nVlLargura'          => $dados['largura'],
            'nVlDiametro'         => $dados['diametro'],
            'sCdMaoPropria'       => (isset($dados['mao_propria']) && $dados['mao_propria'] ? 'S' : 'N'),
            'nVlValorDeclarado'   => (isset($dados['valor_declarado']) ? $dados['valor_declarado'] : 0),
            'sCdAvisoRecebimento' => (isset($dados['aviso_recebimento']) && $dados['aviso_recebimento'] ? 'S' : 'N'),
            'sDtCalculo'          => date('d/m/Y'),
        );
        //die(print_r($params,true));
        $CalcPrecoPrazoData = $soap->CalcPrecoPrazoData($params);
        $resultado          = $CalcPrecoPrazoData->CalcPrecoPrazoDataResult->Servicos->cServico;

        if(!is_array($resultado))
            $resultado = array($resultado);

        $dados = array();

        foreach($resultado as $consulta){
            $consulta = (array) $consulta;

            $dados = array(
                'codigo'             => $consulta['Codigo'],
                'valor'              => (float) str_replace(',','.',$consulta['Valor']),
                'prazo'              => (int) str_replace(',','.',$consulta['PrazoEntrega']),
                'mao_propria'        => (float) str_replace(',','.',$consulta['ValorMaoPropria']),
                'aviso_recebimento'  => (float) str_replace(',','.',$consulta['ValorAvisoRecebimento']),
                'valor_declarado'    => (float) str_replace(',','.',$consulta['ValorValorDeclarado']),
                'entrega_domiciliar' => ($consulta['EntregaDomiciliar'] === 'S' ? true : false),
                'entrega_sabado'     => ($consulta['EntregaSabado'] === 'S' ? true : false),
                'erro'               =>  array('codigo'=> (real)$consulta['Erro'],'mensagem'=>$consulta['MsgErro']),
            );
        }

        return $dados;
    }

    public function cep($cep)
    {
        $data = array(
            'cepEntrada' => $cep,
            'tipoCep'    =>'',
            'cepTemp'    =>'',
            'metodo'     =>'buscarCep',
        );

        $curl = new Curl;

        $html = $curl->simple('http://m.correios.com.br/movel/buscaCepConfirma.do', $data);

        phpQuery::newDocumentHTML($html, $charset = 'utf-8');

        $pq_form = phpQuery::pq('');
        //$pq_form = phpQuery::pq('.divopcoes,.botoes',$pq_form)->remove();
        $pesquisa = array();
        foreach(phpQuery::pq('#frmCep > div') as $pq_div){
            if(phpQuery::pq($pq_div)->is('.caixacampobranco') || phpQuery::pq($pq_div)->is('.caixacampoazul')){
                $dados = array();
                $dados['cliente'] = trim(phpQuery::pq('.resposta:contains("Cliente: ") + .respostadestaque:eq(0)',$pq_div)->text());

                if(count(phpQuery::pq('.resposta:contains("Endereço: ") + .respostadestaque:eq(0)',$pq_div)))
                    $dados['logradouro'] = trim(phpQuery::pq('.resposta:contains("Endereço: ") + .respostadestaque:eq(0)',$pq_div)->text());
                else
                    $dados['logradouro'] = trim(phpQuery::pq('.resposta:contains("Logradouro: ") + .respostadestaque:eq(0)',$pq_div)->text());
                $dados['bairro']    = trim(phpQuery::pq('.resposta:contains("Bairro: ") + .respostadestaque:eq(0)',$pq_div)->text());

                $dados['cidade/uf'] = trim(phpQuery::pq('.resposta:contains("Localidade") + .respostadestaque:eq(0)',$pq_div)->text());
                $dados['cep']       = trim(phpQuery::pq('.resposta:contains("CEP: ") + .respostadestaque:eq(0)',$pq_div)->text());

                $dados['cidade/uf'] = explode('/',$dados['cidade/uf']);

                $dados['cidade']    = trim($dados['cidade/uf'][0]);

                $dados['uf']        = trim($dados['cidade/uf'][1]);

                unset($dados['cidade/uf']);

                $pesquisa = $dados;
            }
        }
        return $pesquisa;
    }

    public function rastrear($codigo)
    {
        $curl = new Curl;

        $html = $curl->simple('http://websro.correios.com.br/sro_bin/txect01$.QueryList?P_LINGUA=001&P_TIPO=001&P_COD_UNI='.$codigo);

        phpQuery::newDocumentHTML($html, $charset = 'utf-8');

        $rastreamento = array();
        $c = 0;

        foreach(phpQuery::pq('tr') as $tr){$c++;
            if(count(phpQuery::pq($tr)->find('td')) == 3 && $c > 1)
                $rastreamento[] = array('data'=>phpQuery::pq($tr)->find('td:eq(0)')->text(),'local'=>phpQuery::pq($tr)->find('td:eq(1)')->text(),'status'=>phpQuery::pq($tr)->find('td:eq(2)')->text());

            if(count(phpQuery::pq($tr)->find('td')) == 1 && $c > 1)
                $rastreamento[count($rastreamento)-1]['encaminhado'] = phpQuery::pq($tr)->find('td:eq(0)')->text();
        }

        if(!count($rastreamento))
            return false;

        return $rastreamento;
    }

}
