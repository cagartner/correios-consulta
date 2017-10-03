<?php

namespace Cagartner\CorreiosConsulta;

use Cagartner\CorreiosConsulta\Curl;
use PhpQuery\PhpQuery as phpQuery;

class CorreiosConsulta
{

    const FRETE_URL    = 'http://ws.correios.com.br/calculador/CalcPrecoPrazo.asmx?WSDL';
    const CEP_URL      = 'http://m.correios.com.br/movel/buscaCepConfirma.do';
    const RASTREIO_URL = 'http://www2.correios.com.br/sistemas/rastreamento/resultado_semcontent.cfm';

    private static $tipos = array(
        'sedex'          => '04014',
        'sedex_a_cobrar' => '40045',
        'sedex_10'       => '40215',
        'sedex_hoje'     => '40290',
        'pac'            => '04510',
        'pac_contrato'   => '04669',
        'sedex_contrato' => '04162',
        'esedex'         => '81019',
    );

    public static function getTipos()
    {
        return self::$tipos;
    }

    /**
     * Verifica se é uma solicitação de varios $tipos
     * 
     * @param $valor string
     * @return boolean
     */
    public static function getTipoIsArray($valor)
    {
        return count(explode(",", $valor)) > 1 ?: false;
    }

    /**
     * @param $valor string
     * @return string
     */
    public static function getTipoIndex($valor)
    {
        return array_search($valor, self::getTipos());
    }

    /**
     * Retorna todos os códigos em uma linha
     * 
     * @param $valor string
     * @return string
     */
    public static function getTipoInline($valor)
    {
        $explode = explode(",", $valor);
        $tipos   = array();

        foreach ($explode as $value)
        {
            $tipos[] = self::$tipos[$value];
        }

        return implode(",", $tipos);
    }

    public function frete($dados, $options = array())
    {
        $endpoint = self::FRETE_URL;

        $tipos = self::getTipoInline($dados['tipo']);

        $formatos = array(
            'caixa'    => 1,
            'rolo'     => 2,
            'envelope' => 3,
        );

        $dados['tipo']    = $tipos;
        $dados['formato'] = $formatos[$dados['formato']];
        /* dados[tipo]
          04014 SEDEX Varejo
          40045 SEDEX a Cobrar Varejo
          40215 SEDEX 10 Varejo
          40290 SEDEX Hoje Varejo
          04510 PAC Varejo
         */

        /*
          1 é Formato caixa/pacote
          2 é Formato rolo/prisma
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

        $CalcPrecoPrazoData = $soap->CalcPrecoPrazoData($params);
        $resultado          = $CalcPrecoPrazoData->CalcPrecoPrazoDataResult->Servicos->cServico;

        if (!is_array($resultado))
            $resultado = array($resultado);

        $dados = array();

        foreach ($resultado as $consulta)
        {
            $consulta = (array) $consulta;

            $dados[] = array(
                'codigo'             => $consulta['Codigo'],
                'valor'              => (float) str_replace(',', '.', $consulta['Valor']),
                'prazo'              => (int) str_replace(',', '.', $consulta['PrazoEntrega']),
                'mao_propria'        => (float) str_replace(',', '.', $consulta['ValorMaoPropria']),
                'aviso_recebimento'  => (float) str_replace(',', '.', $consulta['ValorAvisoRecebimento']),
                'valor_declarado'    => (float) str_replace(',', '.', $consulta['ValorValorDeclarado']),
                'entrega_domiciliar' => ($consulta['EntregaDomiciliar'] === 'S' ? true : false),
                'entrega_sabado'     => ($consulta['EntregaSabado'] === 'S' ? true : false),
                'erro'               => array('codigo' => (real) $consulta['Erro'], 'mensagem' => $consulta['MsgErro']),
            );
        }

        if (self::getTipoIsArray($tipos) === false)
        {
            return isset($dados[0]) ? $dados[0] : [];
        }

        return $dados;
    }

    public function cep($cep)
    {
        $config  = array(
            "trace"      => 1,
            "exception"  => 0,
            "cache_wsdl" => WSDL_CACHE_MEMORY
        );
        $address = __DIR__ . '/../../../public/AtendeCliente.xml';
        $client  = new \SoapClient($address, $config);

        $dados = array();
        try
        {
            $response = $client->consultaCEP(array('cep' => $cep));
            $return   = $response->return;

            $dados['cliente']    = "";
            $dados['logradouro'] = $return->end;
            $dados['bairro']     = $return->bairro;
            $dados['cep']        = $return->cep;
            $dados['cidade']     = $return->cidade;
            $dados['uf']         = $return->uf;
        }
        catch (\Exception $e)
        {
            
        }

        return $dados;
    }

    public function cep2($cep)
    {
        $data = array(
            'cepEntrada' => $cep,
            'tipoCep'    => '',
            'cepTemp'    => '',
            'metodo'     => 'buscarCep',
        );

        $curl = new Curl;

        $html = $curl->simple(self::CEP_URL, $data);

        phpQuery::newDocumentHTML($html, $charset = 'utf-8');

        $pq_form  = phpQuery::pq('');
        //$pq_form = phpQuery::pq('.divopcoes,.botoes',$pq_form)->remove();
        $pesquisa = array();
        foreach (phpQuery::pq('#frmCep > div') as $pq_div)
        {
            if (phpQuery::pq($pq_div)->is('.caixacampobranco') || phpQuery::pq($pq_div)->is('.caixacampoazul'))
            {
                $dados            = array();
                $dados['cliente'] = trim(phpQuery::pq('.resposta:contains("Cliente: ") + .respostadestaque:eq(0)', $pq_div)->text());

                if (count(phpQuery::pq('.resposta:contains("Endereço: ") + .respostadestaque:eq(0)', $pq_div)))
                    $dados['logradouro'] = trim(phpQuery::pq('.resposta:contains("Endereço: ") + .respostadestaque:eq(0)', $pq_div)->text());
                else
                    $dados['logradouro'] = trim(phpQuery::pq('.resposta:contains("Logradouro: ") + .respostadestaque:eq(0)', $pq_div)->text());
                $dados['bairro']     = trim(phpQuery::pq('.resposta:contains("Bairro: ") + .respostadestaque:eq(0)', $pq_div)->text());

                $dados['cidade/uf'] = trim(phpQuery::pq('.resposta:contains("Localidade") + .respostadestaque:eq(0)', $pq_div)->text());
                $dados['cep']       = trim(phpQuery::pq('.resposta:contains("CEP: ") + .respostadestaque:eq(0)', $pq_div)->text());

                $dados['cidade/uf'] = explode('/', $dados['cidade/uf']);

                $dados['cidade'] = trim($dados['cidade/uf'][0]);

                $dados['uf'] = trim($dados['cidade/uf'][1]);

                unset($dados['cidade/uf']);

                $pesquisa = $dados;
            }
        }
        return $pesquisa;
    }

    public function rastrear($codigo)
    {
        $curl = new Curl;

        $html = $curl->simple(self::RASTREIO_URL, array(
            "Objetos" => $codigo
        ));

        phpQuery::newDocumentHTML($html, $charset = 'utf-8');

        $rastreamento = array();
        $c            = 0;

        foreach (phpQuery::pq('tr') as $tr)
        {
            $c++;
            if (count(phpQuery::pq($tr)->find('td')) == 2)
            {
                list($data, $hora, $local) = explode("<br>", phpQuery::pq($tr)->find('td:eq(0)')->html());
                list($status, $encaminhado) = explode("<br>", phpQuery::pq($tr)->find('td:eq(1)')->html());

                $rastreamento[] = array('data' => trim($data) . " " . trim($hora), 'local' => trim($local), 'status' => trim(strip_tags($status)));

                if (trim($encaminhado))
                {
                    $rastreamento[count($rastreamento) - 1]['encaminhado'] = trim($encaminhado);
                }
            }
        }

        if (!count($rastreamento))
            return false;

        return $rastreamento;
    }

}
