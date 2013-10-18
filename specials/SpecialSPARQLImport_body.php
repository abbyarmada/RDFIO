<?php
class SPARQLImport extends SpecialPage {

	function __construct() {
		parent::__construct( 'SPARQLImport' );
		$this->triplesPerBatch = 10; // Limits how many triples are loaded per time
	}

	/**
	 * The main code goes here
	 */
	function execute( $par ) {
		global $wgOut, $wgRequest;
		try {
			$this->setHeaders();

			$submitButtonText = "Import";
			
			// For now, print the result XML from the SPARQL query
			if ( $wgRequest->getText( 'action' ) === 'import' ) {
		        if ( $this->currentUserHasWriteAccess() ) {
		            $offset = $wgRequest->getVal( 'offset', 0 );
		            $limit = $this->triplesPerBatch;
		            $submitButtonText = "Import next $limit triples...";
		            $this->import( $limit, $offset );
		        } else {
		            $this->showErrorMessage("No write access", "The current logged in user does not have write access");
		        }
			} 
		} catch (RDFIOUIException $e) {
			$this->showErrorMessage('Error!', $e->getMessage());
		}
		$wgOut->addHTML( $this->getHTMLForm( $submitButtonText ) );
		
	}
	
	function resourceType( $resourceStr ) {
		if ( substr($resourceStr, 0, 4 ) === 'http' ) {
			return 'uri';
		} else {
			return 'literal';
		}
	}
	
	protected function import( $limit = 10, $offset = 0 ) {
	    global $wgOut, $wgRequest;
	    $externalSparqlUrl = $wgRequest->getText( 'extsparqlurl' );
	    if ( $externalSparqlUrl === '' ) {
	        throw new RDFIOUIException('Empty SPARQL Url provided!');
	    } else if ( !RDFIOUtils::isURI( $externalSparqlUrl ) ) {
	        throw new RDFIOUIException('Invalid SPARQL Url provided! (Must start with \'http://\' or \'https://\')');
	    }
	    $sparqlQuery = urlencode( "SELECT DISTINCT * WHERE { ?s ?p ?o } OFFSET $offset LIMIT $limit" );
	    $sparqlQueryUrl = $externalSparqlUrl . '/' . '?query=' . $sparqlQuery;
	    $sparqlResultXml = file_get_contents($sparqlQueryUrl);
	    
	    $sparqlResultXmlObj = simplexml_load_string($sparqlResultXml);
	    
	    $importTriples = array();
	    
	    if (is_object($sparqlResultXmlObj)) {
	        foreach ($sparqlResultXmlObj->results->children() as $result ) {
	            $triple = array();
	            // $wgOut->addHTML( print_r($result, true) );
	            foreach( $result as $binding ) {
	                if ($binding['name'] == 's') {
	                    $s = (string) $binding->uri[0];
	                    if ($s == '') {
	                        throw new Exception('Could not extract subject from empty string (' . print_r($binding->uri, true) . '), in SPARQLImport');
	                    }
	                    $triple['s'] = $s;
	                    $triple['s_type'] = $this->resourceType($triple['s']);
	                } else if ($binding['name'] == 'p') {
	                    $p = (string) $binding->uri[0];
	                    if ($p == '') {
	                        throw new Exception('Could not extract predicate from empty string (' . print_r($binding->uri, true) . '), in SPARQLImport');
	                    }
	                    $triple['p'] = $p;
	                    $triple['p_type'] = $this->resourceType($triple['p']);
	                } else if ($binding['name'] == 'o') {
	                    $o = (string) $binding->uri[0];
	                    if ($o == '') {
	                        throw new Exception('Could not extract object from empty string (' . print_r($binding->uri, true) . '), in SPARQLImport');
	                    }
	                    $triple['o'] = $o;
	                    $triple['o_type'] = $this->resourceType($triple['o']);
	                    $triple['o_datatype'] = '';
	                }
	            }
	            $importTriples[] = $triple;

	            $rdfImporter = new RDFIORDFImporter();
	            $rdfImporter->importTriples($importTriples);
	            
	            // Provide some user feedback if we were successful so far ...
	             
	            $style_css = <<<EOD
        	    table .rdfio- th {
        	        font-weight: bold;
        	        padding: 2px 4px;
        	    }
        	    table.rdfio-table td,
        	    table.rdfio-table th {
        	        font-size: 11px;
        	    }
        	    .rdfio-successmsg {
        	        color: green;
        	        font-weight: bold;
        	    }
EOD;
	            $wgOut->addInlineStyle($style_css);
	            $wgOut->addHTML("<p class=\"rdfio-successmsg\">Successfully imported the following triples:</p>");
	            $wgOut->addHTML("<table class=\"wikitable sortable rdfio-table\"><tbody><tr><th>Subject</th><th>Predicate</th><th>Object</th></tr>");
	             
	            foreach( $importTriples as $triple ) {
	                $s = $triple['s'];
	                $p = $triple['p'];
	                $o = $triple['o'];
	                if ( RDFIOUtils::isURI( $s )) {
	                    $s = "<a href=\"$s\">$s</a>";
	                }
	                if ( RDFIOUtils::isURI( $p )) {
	                    $p = "<a href=\"$p\">$p</a>";
	                }
	                if ( RDFIOUtils::isURI( $o )) {
	                $o = "<a href=\"$o\">$o</a>";
	                }
	                $wgOut->addHTML("<tr><td>$s</td><td>$p</td><td>$o</td></tr>");
	            }
	             
	            $wgOut->addHTML("</tbody></table>");
	        }
	    } else {
	        $wgOut->addHTML(RDFIOUtils::formatErrorHTML("Error", "There was a problem importing from the endpoint. Are you sure that the given URL is a valid SPARQL endpoint?"));
	    }
    }

	
	protected function getHTMLForm( $buttonText ) {
		global $wgArticlePath, $wgRequest;
		$thisPageUrl = str_replace( '/$1', '', $wgArticlePath ) . "/Special:SPARQLImport";
		$extSparqlUrl = $wgRequest->getText( 'extsparqlurl', '' );
		$limit = $this->triplesPerBatch;
		$offset = $wgRequest->getText( 'offset', 0 - $limit ) + $limit;
		$htmlForm = <<<EOD
		<form method="post" action="$thisPageUrl" >
				URL of SPARQL endpoint:<br>
				<input type="hidden" name="action" value="import">
				<input type="text" name="extsparqlurl" size="60" value="$extSparqlUrl"></input>
				<p><span style="font-style: italic; font-size: 11px">Example: http://www.semantic-systems-biology.org/biogateway/endpoint</span></p>
				<input type="hidden" name="offset" value=$offset>
				<input type="submit" value="$buttonText">
		</form>
EOD;
		return $htmlForm;
	}

	/**
	 * Check whether the current user has rights to edit or create pages
	 */
	protected function currentUserHasWriteAccess() {
		global $wgUser;
		$userRights = $wgUser->getRights();
		return ( in_array( 'edit', $userRights ) && in_array( 'createpage', $userRights ) );
	}

	function showErrorMessage( $title, $message ) {
		global $wgOut;
		$errorHtml = '<div style="margin: .4em 0; padding: .4em .7em; border: 1px solid #FF9999; background-color: #FFDDDD;">
                	 <h3>' . $title . '</h3>
                	 <p>' . $message . '</p>
                	 </div>';
		$wgOut->addHTML( $errorHtml );
	}
	

}
