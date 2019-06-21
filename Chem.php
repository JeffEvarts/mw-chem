<?php

/*
** A note about the various uglinesses in this code
**
** This function essentially implements a lexical analyzer and language
** parser which would (in other situations) be produced by compiler tools
** such as lex/flex and yacc/bison. Unfortunately deployment to Wikipedia
** means that performance IS important. In the absence of clean tools to
** use, hand-tweaked code is The Right Thing here, ugly as it is.
*/
 
$wgExtensionCredits['parserhook'][] = array(
   'path' => __FILE__,
   'name' => 'Chem Parser Function',
   'description' => 'A chemical expression parser and layout function',
   'descriptionmsg' => '',
   'version' => 1, 
   'author' => 'Jeffrey Evarts',
   'url' => 'https://www.mediawiki.org/wiki/Manual:Parser_functions',
);
 
$wgHooks['ParserFirstCallInit'][] = 'ChemSetupParserFunction';
 
$wgExtensionMessagesFiles['Chem'] = __DIR__ . '/Chem.i18n.php';
 
function ChemSetupParserFunction( &$parser ) {
   $parser->setFunctionHook( 'Chem', 'ChemPF' );
   return true;
}

function Tokenize($expr) {
	$element_p =
		'(Uu[tpso]' .        // Unnamed Elements
		'|[A-Z][a-z]' .      // Two-char Elements
		'|[A-Z])+';          // Elements
	$alphas_p =
		'[A-Za-z][A-Za-z \t]*';
	$number_p =
		'[0-9]+';
	$phase_p =
		'[(][a-z]+[)]';
	$paren_p =
		'[()]';
	$bullet_p =
		'\*';
	$plus_p =
		'\+'; 
	$white_p =
		'[ \t]+';
	$other_p =
		'&[A-Za-z0-9]+;|.[ \t]*';

/*
	$lbrace_p =
		'[{]'
	$rbrace_p =
		'[}]';
	$delta_p =
		'dH=|\xce\x94H=';
	$comment_p =
		'/\/\/.*';
	$equal_p =
		 = '-\->|==>|<->|<=>|\xe2\x86\x92|\xe2\x86\x94|=';
*/

	$patterns = array (
		$alphas_p, $number_p, $phase_p, $paren_p, $bullet_p, $plus_p,
		$white_p, $other_p);

	$token_re =
		'/' . implode('|', $patterns) . '/';

	preg_match_all ($token_re, $expr, $match_array);
	$matches = $match_array[0];

	/*
	** HACK: Refine elements pattern after gathering alphabetic sequences.
	** This bumps "plain words" which would not match a list of elements
	** to "other", where it probably belongs.
	*/
	$patterns[0] = $element_p;
	$patterns[6] = '';
	$patterns[7] = '.+';

	$tokens = array();
	foreach ($matches as $str) {
		$str = trim($str);
		$tok_type = 0;
		foreach ($patterns as $pat) {
			if (preg_match("/^$pat\$/", $str)) {
				$tokens[] = array ($tok_type, $str);
				break;
			}
			$tok_type = $tok_type + 1;
		}
	}

	return ($tokens);
}

function ErrPair ($err) {
	return (array(101,"<b>ERROR</b>: $err"));
}

function AcceptAccum(&$accumulator, &$queue, $tokpair = '') {
	if (count($accumulator) > 0) {
		$queue[] = array(100,$accumulator);
		$accumulator = array();
	}
	if ($tokpair != '')
		$queue[] = $tokpair;
}

function Parse ($tokens)  {
	$queue = array();
	$accumulator = array();

	foreach ($tokens as $tokpair) {
		switch($tokpair[0]) {
		case 0: /* Element */
		case 1: /* Number */
		case 2: /* Phases */
		case 3: /* Grouping */
		case 4: /* Bullet */
			$accumulator[] = $tokpair;
		break;
		case 5: /* Plus */
		case 7: /* Other */
			AcceptAccum($accumulator, $queue, $tokpair);
		break;
		case 6: /* Whitespace */
			$last = count($accumulator) - 1;
			if (($last == 0) && ($accumulator[0][0] == 1))
				$queue[] = array_pop($accumulator);
			if ($accumulator[$last][0] == 7)
				$accumulator[$last][1] .= $tokpair[1];
		break;
		default:
			AcceptAccum($accumulator, $queue,
				ErrPair('"' . $tokpair[0] . '"'));
		}
	}
	AcceptAccum($accumulator, $queue);
	return ($queue);
}

function InitialNumberFormat($acc) {
	if ($acc[0][0] != 1)
		return 'Subscript';
	foreach ($acc as $i)
		if ($i[0] == 4)
			return 'Crystal';
	return 'AddSpace';
}
	

function RenderCrystalOrCompound ($acc) {
	$html = '';

	$n_format = InitialNumberFormat($acc);

	foreach ($acc as $i) {
		$text = $i[1];
		$type = $i[0];

		switch($type) {
		case 0: /* Element */
			$level = 1;
			if ($n_format == 'Crystal')
				$n_format = 'Subscript';
		break;
		case 1: /* Number */
			if ($n_format == 'AddSpace')
				$text .= '&nbsp;';
			else if ($n_format == 'Subscript')
				$text = "<sub class=l$level>$text</sub>";
			$n_format = 'Subscript';
		break;
		case 2: /* Phases */
			$text = "<i>$text</i>";
		break;
		case 3: /* Paren */
			$level = 2;
		break;
		case 4: /* Bullet */
			$text = '&bull;';
			$n_format = 'Crystal';
		break;
		default:
			$text = "<b>ERROR:</b> RenderCrystalOrCompound $type";
		}

		$html .= $text;
	}

	return ($html);
}

function RenderSum ($expr) {
	$html = '';
	$level = 0;
	$istream = Parse(Tokenize($expr));
	foreach ($istream as $part) {
		$type = $part[0];
		$content = $part[1];
		switch ($type) {
		case 100: /* Crystal Or Compound */
			$html .= RenderCrystalOrCompound($content);
		break;
		case 1: /* Number */
			$html .= $content . '&nbsp;';
		break;
		case 5: /* Plus */
			$html .= '&nbsp;+&nbsp;';
		break;
		case 7: /* Other */
			$html .= "$content";
		break;
		default:
			$html .= "<b>ERROR:</b> RenderSum ($type)";
		}
	}
	return ($html);
}

function RenderArrow () {
	return ' <span class=chem-arr>&rarr;</span> ';
}

function RenderBigArrow($before, $after) {
	return
  		"<div class='chem-arrow-block'>".
		"<span class='chem-z'>{</span>".
	        RenderSum($before).
   		"<div class='chem-shaft'>".
		"<div class=chem-head><span class='chem-z'>&rarr;</span></div>".
		"</div>".
		$after.
		"<span class='chem-z'>}</span>".
		"</div>";
}

function RenderDelta($delta) {
	return "<span class='chem-dh'>&Delta;H=$delta</span>";
}

function RenderComment($comment) {
	return "<span class=chemcom>&nbsp;//&nbsp;$comment</span>";
}

function ChemPF ($parser, $expr='')  {
	/*
	** Step one: Downconvert parsed chars from UTF to shorthand
	** FIXME: this downconverts strings which are NOT subsequently
	** re-promoted (such as in a comment) which sucks.
	*/
	$dtri = '/\xce\x94H=/';
	$expr = preg_replace($dtri,'dH=', $expr);
	$arrows = '/-\->|==>|<->|<=>|\xe2\x86\x92|\xe2\x86\x94/';
	$expr = preg_replace($arrows, '=', $expr);

	/*
	** Step two: Split the statement into segments. Different
	** segments are rendered differently, so we need to do this
	** before we begin tokenizing.
	*/
	$split = '/(dH=|{[^=}]*=[^=}]*}|=|\/\/)/';
	$sections = preg_split($split,$expr,0, PREG_SPLIT_DELIM_CAPTURE);

	$left = '';
	$before = '';
	$after = '';
	$right = '';
	$delta = '';
	$comment = '';

	$eql = 0;

	while (count($sections) > 0) {
		$sec = trim(array_shift($sections));
		if ($sec == '')
			continue;
		if ($sec == '=') {
			$eql = $eql + 1;
			continue;
		}
		if ($sec == 'dH=') {
			$delta = trim(array_shift($sections));
			continue;
		}
		if ($sec == '//') {
			$comment = trim(array_shift($sections));
			continue;
		}
		if (substr($sec,0,1) == '{') {
			$arr = preg_split ('/=/',trim(substr($sec,1,-1)));
			$before = trim($arr[0]);
			$after = trim($arr[1]);
			$eql = $eql + 1;
			continue;
		}
		if ($eql == 0)
			$left = $sec;
		else
			$right = $sec;
	}

	if (($left == '' && $delta == '' && $comment == '') || ($eql > 1))
		return
			'<b>ERROR:</b> Malformed expression (' .
			"$left, $delta, $comment, $eql)\n\t'$expr'\n";

	$html = RenderSum($left);
	if ($right != '') {
		if ($before == '' && $after == '')
			$html .= RenderArrow();
		else 
			$html .= RenderBigArrow($before,$after);
		$html .= RenderSum($right);
	}
	if ($delta != '')
		$html .= RenderDelta($delta);
	if ($comment != '')
		$html .= RenderComment($comment);

	$html = "<span class=chem>$html</span>";

	return ($html);
}

?>
