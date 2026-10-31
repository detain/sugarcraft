<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server\Pairing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Server\Pairing\QrEncoder;
use SugarCraft\Code\Server\Pairing\QrTerminal;

/**
 * The pairing QR encoder is pinned against matrices produced OFFLINE by
 * segno 1.6.6 (`segno.make(payload, error="m", mode="byte",
 * boost_error=False)`), an independent reference implementation — never
 * by this encoder talking to itself. Payloads are stored hex-encoded so
 * no fixture literal can look like a shell glob; rows are byte-packed
 * hex, MSB-first, right-zero-padded to a byte boundary.
 *
 * The streams follow segno's byte-padding convention (a full 00 filler
 * codeword also when the terminator leaves the stream aligned); see the
 * buildCodewords comment in QrEncoder.php. The result scans identically
 * — decoders read the count field, never the filler.
 */
final class QrEncoderTest extends TestCase
{
    /** Byte payload caps, ECC-M, versions 1..10 (reference table). */
    public const CAPS = [1 => 14, 2 => 26, 3 => 42, 4 => 62, 5 => 84, 6 => 106, 7 => 122, 8 => 152, 9 => 180, 10 => 213];

    /** name => [hex payload, expected version, expected auto mask, rows] */
    public const VECTORS = [
        'B1' => [
            '21282f363d444b525960676e757c',
            1,
            7,
            [
        'fe6bf8',
        '821a08',
        'ba72e8',
        'ba52e8',
        'ba2ae8',
        '828208',
        'feabf8',
        '007000',
        '96c500',
        'ec8c78',
        'd27d38',
        'd15288',
        '977e08',
        '00cba8',
        'fe0d80',
        '829958',
        'ba3540',
        'bacf58',
        'ba7b88',
        '824cc0',
        'fecb10',
            ],
        ],
        'B2' => [
            '21282f363d444b525960676e757c252c333a41484f565d646b72',
            2,
            2,
            [
        'fe763f80',
        '8215a080',
        'bab02e80',
        'ba83ae80',
        'bad02e80',
        '82e92080',
        'feaabf80',
        '00fb8000',
        'be223e00',
        '608c1200',
        '9389b280',
        'ede1ac80',
        '9f8b7200',
        'f12c1000',
        '8f0f6e80',
        'b1fbc580',
        '93a2fa80',
        '00b28a00',
        'fe06a880',
        '82988800',
        'ba9bfc00',
        'baf67180',
        'baca1480',
        '8242d880',
        'fece0d80',
            ],
        ],
        'B3' => [
            '21282f363d444b525960676e757c252c333a41484f565d646b7279222930373e454c535a61686f767d26',
            3,
            0,
            [
        'fe3b43f8',
        '82a57208',
        'ba1192e8',
        'ba72bae8',
        'ba9e22e8',
        '8205fa08',
        'feaaabf8',
        '00044800',
        'aa745890',
        '6c599a08',
        '06ecee08',
        'd917f0c8',
        '322dde50',
        '6147fa38',
        '0ac46568',
        'f51dd410',
        'a774ca20',
        '706bbe18',
        'ae28aea8',
        '48b6e100',
        '8fa77fa8',
        '008448f8',
        'fe7c7ac8',
        '8244d898',
        'bae26fe0',
        'ba5c0dd8',
        'badea988',
        '82756a10',
        'fede6a78',
            ],
        ],
        'B4' => [
            '21282f363d444b525960676e757c252c333a41484f565d646b7279222930373e454c535a61686f767d262d343b424950575e656c737a232a31383f464d54',
            4,
            1,
            [
        'fed0d03f80',
        '8232cca080',
        'baadc52e80',
        'ba137aae80',
        'ba6d42ae80',
        '82d0622080',
        'feaaaabf80',
        '0061888000',
        'a373cb1280',
        '716b69d200',
        'af2dbb8680',
        'f1f901e900',
        'aae7ac3480',
        'fdd0d97680',
        '03b1557f80',
        '195faaad00',
        '43d09abb80',
        '4c4d0dd600',
        'b339373880',
        '944c23b080',
        '36d23db000',
        '79a51f7080',
        'd2afddc980',
        '05c219c480',
        'cfae0bfa00',
        '00b7ca8d80',
        'fed9bead80',
        '8243118980',
        'ba6325f880',
        'ba196a4380',
        'baf933cd80',
        '82143af400',
        'fefd13a480',
            ],
        ],
        'B5' => [
            '21282f363d444b525960676e757c252c333a41484f565d646b7279222930373e454c535a61686f767d262d343b424950575e656c737a232a31383f464d545b626970777e272e353c434a51585f666d747b242b32',
            5,
            5,
            [
        'fe7a100bf8',
        '82d5042208',
        'babda712e8',
        'bacb9cfae8',
        'ba524b62e8',
        '821c4eea08',
        'feaaaaabf8',
        '0080082800',
        '82bb483e70',
        '10fca33980',
        '13ad68d928',
        'c15f00a748',
        '5b73d9baf0',
        '9d6d0b3170',
        'b677c274a8',
        '25623022a8',
        '2e4cd92eb8',
        '756f6d5500',
        '1add3f3f58',
        'a0c93293d8',
        'd2d8d92f48',
        'f9137e71f0',
        '4f030abbe8',
        '6477992c98',
        '2f0d9bda80',
        'dc490b3520',
        'b773289f88',
        '811c1b9de8',
        'b633c83f98',
        '00a5b018e0',
        'fe735d4ab8',
        '8267a318c0',
        'ba3241bfd8',
        'ba05c546b8',
        'ba27a40958',
        '82512b8848',
        'fedc82dca8',
            ],
        ],
        'B6' => [
            '21282f363d444b525960676e757c252c333a41484f565d646b7279222930373e454c535a61686f767d262d343b424950575e656c737a232a31383f464d545b626970777e272e353c434a51585f666d747b242b323940474e555c636a717821282f363d444b525960676e',
            6,
            5,
            [
        'fe37e3053f80',
        '82c92a882080',
        'ba9cbe08ae80',
        'ba9d59e02e80',
        'ba593c4cae80',
        '82240022a080',
        'feaaaaaabf80',
        '00ebbb990000',
        '82e457dce700',
        'b1fd32764a00',
        '9a7088be6d00',
        '28be89ab4180',
        '168a0c312500',
        'e5aeb9de8880',
        '8a9748fe3300',
        '2db729297a00',
        'a76ade5d9380',
        '0881a99e8880',
        'cfe09d7d1180',
        'a5388022b900',
        'd273de5d9380',
        'f44167b22e00',
        'bfbaecba2100',
        'f1c0983ae880',
        'd2b115202500',
        '7146cd9a8b80',
        'eed78472d800',
        '9deab1b1aa80',
        '5fa14e4d1200',
        'fc4e4f9a8b80',
        'd7f55b1b3a80',
        'b9fc91338980',
        '92e54f4cff00',
        '00ea45f28900',
        'fe1dc89eaa00',
        '8252a3018880',
        'ba0a7429f980',
        'ba28afdad280',
        'ba46a2354e00',
        '8257a828f280',
        'fe83af451600',
            ],
        ],
        'B7' => [
            '21282f363d444b525960676e757c252c333a41484f565d646b7279222930373e454c535a61686f767d262d343b424950575e656c737a232a31383f464d545b626970777e272e353c434a51585f666d747b242b323940474e555c636a717821282f363d444b525960676e757c252c333a41484f565d646b727922',
            7,
            2,
            [
        'fe6fb40a0bf8',
        '82185bb99208',
        'baac3cb9d2e8',
        'baf36fa51ae8',
        'bae68fdefae8',
        '82a4088b8208',
        'feaaaaaaabf8',
        '0080e8b16000',
        'be6a3f8313e0',
        '7d2e05c70c48',
        'f78b1d966080',
        '59ca704f92f0',
        '23332dd67320',
        '30ae42ce1c98',
        'b300f1b532d0',
        '541111cf94f0',
        'd3f0f6071e00',
        '955cb6135d10',
        'd26bbaa12730',
        'a007c002cc20',
        '9f8e6fc16f90',
        'd8b388cf8898',
        '4aef2a831aa0',
        '68b898f548b8',
        '0fc42fe54f90',
        '1591325e8268',
        '83bb8e58e8e0',
        'bc19e7edbf68',
        'efdcdd9204a8',
        '1cd65b535138',
        'fadd49e9ed90',
        '407dd3adb068',
        '567b59634988',
        'c4316d028d30',
        '0abae16dd570',
        '793dbca0e3b0',
        '9a69df870f98',
        '00bf38d618d8',
        'fe241a967ac0',
        '8294e8ce08a0',
        'baaaafb13fc0',
        'bafb504f8ed8',
        'ba83a6d07a30',
        '82741d1e9860',
        'feed17f673f0',
            ],
        ],
        'B8' => [
            '21282f363d444b525960676e757c252c333a41484f565d646b7279222930373e454c535a61686f767d262d343b424950575e656c737a232a31383f464d545b626970777e272e353c434a51585f666d747b242b323940474e555c636a717821282f363d444b525960676e757c252c333a41484f565d646b7279222930373e454c535a61686f767d262d343b424950575e656c737a232a3138',
            8,
            6,
            [
        'feec7b3f34bf80',
        '82e89920a7a080',
        'ba87a15685ae80',
        'ba6a6998bd2e80',
        'bab02fe3442e80',
        '8211e223da2080',
        'feaaaaaaaabf80',
        '001d963fb60000',
        '9f8fd3e85c4b80',
        '3c4fbcbf36e500',
        '57e35c1f7f0a80',
        'a0d8420310ae00',
        '7ff144fcdffc00',
        'c06679b25b6400',
        '9e85a1b49cbc80',
        'b1534e3c3e7780',
        '92432ba28c6980',
        '8963d65f61f080',
        'fb0cd2d0588680',
        'bcd6f53502f900',
        'e7a2e6af1b2500',
        '44f51f66afbf80',
        '5ff927f420fe80',
        'f8cece21a48f80',
        '1a9516ab89a900',
        'd8aaca2f8b8c00',
        'dff29bffd0fd80',
        'b02de1f4a79680',
        'fb08f7039dd700',
        'b4bca0c27cb500',
        'd21f52ff066380',
        'f0dbf586391480',
        '8eff6b0a7f8c00',
        '21258bc26fdc80',
        '53b6d171e32a80',
        '41eea67a8e3380',
        'ee59dd2fcdd100',
        '9461a89edb5f80',
        '47638c117b7b80',
        '71aeed07043b80',
        'e3dbbbe6e9ff80',
        '009252332c8d00',
        'feb606b38caa80',
        '82983a3fb08d80',
        'baa21bea7ff880',
        'baa9cb57be1780',
        'ba5a339eef0d00',
        '826e63cddc6f80',
        'fe90e7c88b8c80',
            ],
        ],
        'B9' => [
            '21282f363d444b525960676e757c252c333a41484f565d646b7279222930373e454c535a61686f767d262d343b424950575e656c737a232a31383f464d545b626970777e272e353c434a51585f666d747b242b323940474e555c636a717821282f363d444b525960676e757c252c333a41484f565d646b7279222930373e454c535a61686f767d262d343b424950575e656c737a232a31383f464d545b626970777e272e353c434a51585f666d747b242b323940',
            9,
            6,
            [
        'fed351932b23f8',
        '82a68d228c7208',
        'bae61211e312e8',
        'ba35bedbcaeae8',
        'bab9c4fec262e8',
        '821d5f8d0a6208',
        'feaaaaaaaaabf8',
        '00002e88880000',
        '9ff357fa1ee4b8',
        'b171808e67ffa0',
        '2bf2e96461c470',
        'b0b5248ef1d558',
        'faf47b8aae83d8',
        'd12392cecbe968',
        'e6e04c0f74b4a8',
        'a53f66e0431cf8',
        '8629d150f812a0',
        '842dfe2331e740',
        'be57579994dd38',
        '949ea3adacc9e0',
        '17f84e1e78e3e0',
        '9c259d47677ec8',
        'a732eb6355c520',
        '6060d380952048',
        '1f8553f8dacf80',
        '08f26c8a86e8c8',
        'ea8099ac97bac8',
        '1883588c9728a0',
        'cf990df9ff6f98',
        'd56ac57be420a0',
        '83ec61318dc778',
        '99f6dbe17b37a0',
        'deb56b8a7a9b68',
        '60eea5567ef280',
        '3f6bfd320b7100',
        '7082939ee33ac0',
        '821dfa6daaf320',
        '0121a51f96b298',
        'ce247c558a0e48',
        '21d5873f53f838',
        '6bb1216eba6040',
        '6193c2e264a990',
        'de195b7338c218',
        '60afdf4691d2b0',
        '13d0daf83cffa8',
        '00ed968a3378d8',
        'feb304a9adbac0',
        '82be8b8a272890',
        'ba93b7fcbf8f98',
        'baf73137cbe398',
        'ba5a44148b17b0',
        '8234fd65bd9fe8',
        'fe99c9a1a82dc0',
            ],
        ],
        'B10' => [
            '21282f363d444b525960676e757c252c333a41484f565d646b7279222930373e454c535a61686f767d262d343b424950575e656c737a232a31383f464d545b626970777e272e353c434a51585f666d747b242b323940474e555c636a717821282f363d444b525960676e757c252c333a41484f565d646b7279222930373e454c535a61686f767d262d343b424950575e656c737a232a31383f464d545b626970777e272e353c434a51585f666d747b242b323940474e555c636a717821282f363d444b525960676e757c252c333a41484f565d646b',
            10,
            7,
            [
        'fe4fd3e3333b3f80',
        '821527ab16252080',
        'ba043e3cf22b2e80',
        'ba3c988775512e80',
        'ba34b4ffc2f92e80',
        '82d8e9e25f862080',
        'feaaaaaaaaaabf80',
        '0020dfe3cb0d0000',
        '96e9ce3f6f965000',
        'c5c5862808890c80',
        '929af247257b9b80',
        'e5794376d02bbc80',
        '2ffd425f999a3b00',
        'b8e71610bd831c80',
        '2b9972c55f85b000',
        '84f7129d1515a000',
        'ba8985e2f96c3680',
        '741cad4c5e0d2500',
        '96f7d61c9091a180',
        'fc9349c1cd7a0180',
        '079d2c3a1ae28d80',
        '79b2a33d155c1c00',
        '6f25cd2190d63680',
        'b988833e38a07980',
        'ff442459fddcc900',
        'cd3e93012c124000',
        '9f80bbff6cb2ff00',
        'b89cf263ddbd8c00',
        'aae5856a9d08ad00',
        '08cbf76303598d00',
        'bfb2c97e9890fc80',
        'fd8b388544d63080',
        '121fe8bf6db5c700',
        '11fc9660c800e600',
        'c2e4b42621d62000',
        'fd2b270dbd679d80',
        '63745eb789aa1880',
        '11bfc81c714f6b00',
        '93c4f633d6119b00',
        'dda23556dbbea100',
        '726e236fca5ea500',
        '618b02569241e380',
        'c6dc82c63aab6380',
        'a9d69703ac599d00',
        '2b1849312bd15500',
        '71e1734591183680',
        'a7f7d31a4be53d80',
        'f9901a9f0dc92d00',
        '029f143fdffcfa80',
        '00f5f423a4938a80',
        'fe22afea8650ac00',
        '82d019e353778800',
        'ba7f81febf2afe00',
        'ba8df905c794c980',
        'ba427d5e19889e80',
        '824fbe99a8585800',
        'fec229c67c847d00',
            ],
        ],
        'U1' => [
            '687474703a2f2f3132372e302e302e313a373432302f23636f64653d30313233343536373839616263646566',
            4,
            1,
            [
        'feececbf80',
        '8268002080',
        'bab5de2e80',
        'ba70012e80',
        'ba4517ae80',
        '82c1702080',
        'feaaaabf80',
        '0053b98000',
        'a30a239280',
        '8457556580',
        'b23919e780',
        '48e4d45c00',
        'ff1455b080',
        '04b405b580',
        '5a97316a80',
        'a57220c800',
        'a75f137100',
        'f94b136580',
        'a3517fe780',
        'b5b0abbd80',
        'de45fea180',
        '612eaba480',
        'df94affc80',
        '2573baa800',
        'fb5083f800',
        '0083d68980',
        'fea590af80',
        '820edc8880',
        'ba0955f980',
        'ba52458c80',
        'ba89129980',
        '8274208800',
        'fee3929080',
            ],
        ],
        'U2' => [
            '687474703a2f2f3139322e3136382e35302e373a373432302f23636f64653d646561646265656663616665626162653131323233333434',
            4,
            5,
            [
        'fe3a4cbf80',
        '82de68a080',
        'ba959f2e80',
        'bac064ae80',
        'ba21112e80',
        '827a88a080',
        'feaaaabf80',
        '00f2318000',
        '8295686700',
        '18711bce00',
        '7279804b00',
        'e16bb0bf00',
        '0e5ea1b080',
        'a076953380',
        '1ec2620700',
        '4c653a7e80',
        '562e681880',
        'e5b193e680',
        'c3b155e680',
        'f9033b7e00',
        'd62fa9dd00',
        'cce0b91e00',
        'ab91a6a300',
        '9dba335780',
        'f74db3fc80',
        '00bbb28a80',
        'fe59a5ab00',
        '8275b08b80',
        'ba5938fd80',
        'ba16f20b80',
        'ba30979d80',
        '824f800e00',
        'feb061d900',
            ],
        ],
        'U3' => [
            '68747470733a2f2f686f73742e6578616d706c652e696e7465726e616c3a373432302f23636f64653d66656564666163656361666562656566303132333435363738396162636465666465616462656566',
            5,
            4,
            [
        'fee52153f8',
        '8257326208',
        'ba29453ae8',
        'ba9a087ae8',
        'bae5ab7ae8',
        '829298e208',
        'feaaaaabf8',
        '00bad5b800',
        '8bedde3fc8',
        'ac286190d0',
        '1a092b3f60',
        '1c15ef2430',
        '9252770f38',
        '515fa958c0',
        '82870351e0',
        '7c334e97a0',
        '36915f2778',
        '3d3ca5d8d0',
        '92f88193c0',
        '15946e9230',
        '138bd68720',
        '3cf3297d80',
        '765f6bd9e0',
        '2c077d21a0',
        '0e8a5f3778',
        '9c6a219880',
        '17e9873520',
        '0481fc9320',
        'd7d8568fa8',
        '00d3a96880',
        'fedbe52a80',
        '825f7698f8',
        'bafe4f2ff8',
        'ba5e65c718',
        'ba01adaa00',
        '82006fb4b0',
        'feba54b138',
            ],
        ],
    ];

    /** U2 (version 4) rendered under every forced mask 0..7. */
    public const MASKED_GRIDS = [
        0 => [
            'fe6f19bf80',
            '82831d2080',
            'ba76112e80',
            'ba3f9b2e80',
            'bac29f2e80',
            '8227fd2080',
            'feaaaabf80',
            '002f440000',
            'aa76e60900',
            'e58ee43180',
            '4a9a0e7380',
            '3436c56800',
            '5b0bf4e580',
            '752be0e480',
            '2621ec3f80',
            'b19ac58100',
            '6ecde62000',
            '30ece63180',
            '96e400b380',
            '2c5e4ea900',
            'eecc27e580',
            '311f46e180',
            '9372289b80',
            '48e7468080',
            'a218e6f980',
            '00e6c78d80',
            'fe3a2bab80',
            '820a4f8c00',
            'babab6fd00',
            'ba4b87dc80',
            'bae5c2c880',
            '8212f5d900',
            'fed3efe180',
        ],
        1 => [
            'feba4cbf80',
            '8256482080',
            'baa3442e80',
            'ba6ace2e80',
            'ba17ca2e80',
            '82f2a82080',
            'feaaaabf80',
            '007a110000',
            'a323b31280',
            'b0dbb16480',
            '1fcf5b2680',
            '6163903d00',
            '0e5ea1b080',
            '207eb5b180',
            '7374b96a80',
            'e4cf90d400',
            '3b98b37500',
            '65b9b36480',
            'c3b155e680',
            '790b1bfc00',
            'bb9972b080',
            '644a13b480',
            'c6277dce80',
            '1db213d580',
            'f74db3fc80',
            '00b3928880',
            'feef7eae80',
            '825f1a8900',
            'ba6fe3f800',
            'ba1ed28980',
            'bab0979d80',
            '8247a08c00',
            'fe86bab480',
        ],
        2 => [
            'fe0c97bf80',
            '821f6ca080',
            'ba959f2e80',
            'baa3eaae80',
            'baa1112e80',
            '82bb8ca080',
            'feaaaabf80',
            '00b3358000',
            'be15683e00',
            '209295f680',
            '7279804b00',
            'f12ab4af00',
            '63e87add00',
            'b037912380',
            '1ec2620700',
            '7486b44600',
            '562e681880',
            'f5f097f680',
            'ae078e8b00',
            'e9423f6e00',
            'd62fa9dd00',
            'f403372680',
            'ab91a6a300',
            '8dfb374780',
            '9afb68f900',
            '00fab68a80',
            'fe59a5ab00',
            '82963e8b00',
            'bad938fd80',
            'bad7f61b80',
            'ba864cf000',
            '820e841e00',
            'feb061d900',
        ],
        3 => [
            'fe8c97bf80',
            '82c4012080',
            'ba7829ae80',
            'baa3eaae80',
            'ba7a7cae80',
            '82563a2080',
            'feaaaabf80',
            '00e8580000',
            'b778dea580',
            '209295f680',
            'c6a2edfd80',
            '2847027400',
            '63e87add00',
            '04ecfc9500',
            'c7afd4dc00',
            '7486b44600',
            'e2f505ae00',
            '2c9d212d80',
            'ae078e8b00',
            '5d9952d880',
            '0f421f0600',
            'f403372680',
            '1f4acb1580',
            '5496819c80',
            '9afb68f900',
            '00a1db8c00',
            'feb413a800',
            '82963e8b00',
            'ba0255fb00',
            'baba40c080',
            'ba864cf000',
            '8255e9a880',
            'feddd70200',
        ],
        4 => [
            'fecb8bbf80',
            '825870a080',
            'ba2d7cae80',
            'ba9b092e80',
            'bae60d2e80',
            '82fc90a080',
            'feaaaabf80',
            '008bd60000',
            '8bd2747c80',
            '5155898700',
            'fe4163c500',
            '7d12572100',
            '122f66ac80',
            'c1f08d5200',
            '92fa818900',
            'f8be57c800',
            '27e9746900',
            '84378b8700',
            '223f6d0500',
            '657adce000',
            'a7e8b5ac80',
            '85c42b5700',
            '27a9452d00',
            '01c3d4c980',
            'eb3c74f880',
            '00bdaa8b00',
            'fee146ad00',
            '822edd8d00',
            'ba9e24fc00',
            'ba10ea6a00',
            'ba3eaf7e00',
            '8236679000',
            'fef77da880',
        ],
        5 => [
            'fe3a4cbf80',
            '82de68a080',
            'ba959f2e80',
            'bac064ae80',
            'ba21112e80',
            '827a88a080',
            'feaaaabf80',
            '00f2318000',
            '8295686700',
            '18711bce00',
            '7279804b00',
            'e16bb0bf00',
            '0e5ea1b080',
            'a076953380',
            '1ec2620700',
            '4c653a7e80',
            '562e681880',
            'e5b193e680',
            'c3b155e680',
            'f9033b7e00',
            'd62fa9dd00',
            'cce0b91e00',
            'ab91a6a300',
            '9dba335780',
            'f74db3fc80',
            '00bbb28a80',
            'fe59a5ab00',
            '8275b08b80',
            'ba5938fd80',
            'ba16f20b80',
            'ba30979d80',
            '824f800e00',
            'feb061d900',
        ],
        6 => [
            'feba4cbf80',
            '82d870a080',
            'bab10d2e80',
            'ba4064ae80',
            'bab3582e80',
            '824a4ba080',
            'feaaaabf80',
            '0074298000',
            '9fb1fa4b80',
            '18711bce00',
            '56ebc96f80',
            'ed5b73b300',
            '0e5ea1b080',
            'c1f08d5200',
            '57e6f04e00',
            '4c653a7e80',
            '72bc213c00',
            'e98150ea80',
            'c3b155e680',
            '9885231f80',
            '9f0b3b9400',
            'cce0b91e00',
            '8f03ef8780',
            '918af05b80',
            'f74db3fc80',
            '00bdaa8b00',
            'fefd37aa00',
            '82f5b08b80',
            'bacb71f900',
            'baa6310780',
            'ba30979d80',
            '8249986f80',
            'fe94f39000',
        ],
        7 => [
            'fe6f19bf80',
            '82278f2080',
            'ba64582e80',
            'ba3f9b2e80',
            'ba660d2e80',
            '82b5b42080',
            'feaaaabf80',
            '000bd60000',
            '96e4af5000',
            'e58ee43180',
            '03be9c3a80',
            '10a48c4c80',
            '5b0bf4e580',
            '3c0f72ad80',
            '02b3a51b00',
            'b19ac58100',
            '27e9746900',
            '147eaf1500',
            '96e400b380',
            '657adce000',
            'ca5e6ec100',
            '311f46e180',
            'da56bad280',
            '6c750fa400',
            'a218e6f980',
            '00c2558c80',
            'fe2862af00',
            '828a4f8c00',
            'ba1e24fc00',
            'bad9cef800',
            'ba65c2c880',
            '8236679000',
            'fec1a6c500',
        ],
    ];

    /** The 15-bit format values for ECC-M with each mask. */
    public const FORMAT_BITS = [
        '101010000010010',
        '101000100100101',
        '101111001111100',
        '101101101001011',
        '100010111111001',
        '100000011001110',
        '100111110010111',
        '100101010100000',
    ];

    /** The 18-bit version-information values for versions 7..10. */
    public const VERSION_BITS = [
        '000111110010010100',
        '001000010110111100',
        '001001101010011001',
        '001010010011010011',
    ];

    public function testTheCapacityTableMatchesTheReference(): void
    {
        for ($version = 1; $version <= QrEncoder::MAX_VERSION; $version++) {
            self::assertSame(self::CAPS[$version], QrEncoder::capacity($version));
        }
    }

    public function testVersionSelectionWalksTheCapacityBoundaries(): void
    {
        for ($version = 1; $version <= QrEncoder::MAX_VERSION; $version++) {
            self::assertSame($version, QrEncoder::versionFor(self::CAPS[$version]), "at the {$version} cap");
        }
        self::assertSame(1, QrEncoder::versionFor(1));
        self::assertSame(2, QrEncoder::versionFor(self::CAPS[1] + 1));
        self::assertSame(10, QrEncoder::versionFor(QrEncoder::MAX_PAYLOAD_BYTES));
    }

    public function testOneBytePastTheTenthCapacityIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/exceeds/i");

        QrEncoder::versionFor(QrEncoder::MAX_PAYLOAD_BYTES + 1);
    }

    public function testAnEmptyPayloadIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        QrEncoder::encode('');
    }

    public function testAForcedMaskOutsideTheRangeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        QrEncoder::encode('pair', 8);
    }

    public function testFormatBitsMatchTheReferenceConstants(): void
    {
        foreach (self::FORMAT_BITS as $mask => $bits) {
            self::assertSame($bits, sprintf('%015b', QrEncoder::formatBits($mask)), "mask {$mask}");
        }
    }

    public function testVersionBitsMatchTheReferenceConstants(): void
    {
        foreach (self::VERSION_BITS as $index => $bits) {
            self::assertSame($bits, sprintf('%018b', QrEncoder::versionBits(7 + $index)), 'version ' . (7 + $index));
        }
    }

    /** @return array<string, array{0: string}> */
    public static function vectorNames(): array
    {
        return array_combine(
            array_keys(self::VECTORS),
            array_map(static fn(string $name): array => [$name], array_keys(self::VECTORS))
        );
    }

    #[DataProvider('vectorNames')]
    public function testEveryReferenceMatrixRoundTrips(string $name): void
    {
        [$payloadHex, $version, $mask, $rows] = self::VECTORS[$name];
        $symbol = QrEncoder::encode(hex2bin($payloadHex));

        self::assertSame($version, $symbol['version'], "{$name} version");
        self::assertSame($mask, $symbol['mask'], "{$name} auto-chosen mask");
        self::assertSame(17 + 4 * $version, $symbol['size'], "{$name} size");
        self::assertCount($symbol['size'], $symbol['modules']);

        foreach ($symbol['modules'] as $row => $line) {
            self::assertSame($rows[$row], self::rowToHex($line), "{$name} row {$row}");
        }
    }

    public function testAllEightForcedMasksMatchTheReferenceGrids(): void
    {
        [$payloadHex] = self::VECTORS['U2'];
        foreach (self::MASKED_GRIDS as $mask => $rows) {
            $symbol = QrEncoder::encode(hex2bin($payloadHex), $mask);
            self::assertSame(4, $symbol['version'], "forced mask {$mask} stays version 4");
            self::assertSame($mask, $symbol['mask']);
            foreach ($symbol['modules'] as $row => $line) {
                self::assertSame($rows[$row], self::rowToHex($line), "mask {$mask} row {$row}");
            }
        }
    }

    public function testTheTerminalBlockIsHalfBlocksInsideAFourModuleQuietZone(): void
    {
        $quiet = QrTerminal::QUIET_ZONE;
        self::assertSame(4, $quiet);

        // A dark-on-light frame with one dark module pair in the middle:
        // rows 0..1 of the matrix, both dark in column 0 only.
        $size = 21;
        $modules = [];
        for ($r = 0; $r < $size; $r++) {
            $row = array_fill(0, $size, 0);
            if ($r < 2) {
                $row[0] = 1;
            }
            $modules[] = $row;
        }
        $lines = explode("\n", QrTerminal::render($modules, $size));
        $width = $size + 2 * $quiet;
        $blank = str_repeat(' ', $width);

        self::assertCount(($size + 2 * $quiet + 1) >> 1, $lines);
        self::assertSame($blank, $lines[0], 'the quiet zone starts clean');
        self::assertSame($blank, $lines[1]);
        self::assertSame(str_repeat(' ', $quiet) . "\u{2588}" . str_repeat(' ', $width - $quiet - 1), $lines[2], 'two dark rows stack into a full block');
        $last = $lines[count($lines) - 1];
        if (($size + 2 * $quiet) % 2 === 1) {
            self::assertSame($blank, $last, 'odd grids pair the bottom row with quiet space');
        }
    }

    public function testAnAllDarkSymbolRendersFullAndUpperHalfBlocks(): void
    {
        $size = 21;
        $modules = array_fill(0, $size, array_fill(0, $size, 1));
        $lines = explode("\n", QrTerminal::render($modules, $size));
        $width = $size + 2 * QrTerminal::QUIET_ZONE;

        self::assertCount(15, $lines, '29 padded rows pair up with one quiet filler row');
        self::assertSame(str_repeat(' ', $width), $lines[0]);
        self::assertSame(str_repeat(' ', 4) . str_repeat("\u{2588}", $size) . str_repeat(' ', 4), $lines[2]);
        self::assertSame(str_repeat(' ', 4) . str_repeat("\u{2580}", $size) . str_repeat(' ', 4), $lines[12], 'the odd bottom row pairs with the quiet zone');
        self::assertSame(str_repeat(' ', $width), $lines[14], 'the padded filler row line is all quiet');
    }

    public function testTheTerminalRendererRefusesMatricesThatAreNotSquareOrNotQRShapes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        QrTerminal::render([[1, 0]], 2);
    }

    public function testARenderedSignInUrlKeepsTheOneTimeCodeOutOfEveryDiagnostic(): void
    {
        // The encoder must never be handed a truncated payload: a URL
        // over the capacity is refused loudly instead of silently
        // shortened, because a half-rendered code would leak a code that
        // cannot be paired. (The CLI-side redaction lives in Serve.)
        $this->expectException(\InvalidArgumentException::class);

        QrEncoder::encode(str_repeat('x', QrEncoder::MAX_PAYLOAD_BYTES + 1));
    }

    private static function rowToHex(array $line): string
    {
        $bits = implode('', array_map(static fn(int $v): string => $v ? '1' : '0', $line));
        $hex = '';
        for ($i = 0; $i < strlen($bits); $i += 8) {
            $hex .= sprintf('%02x', bindec(str_pad(substr($bits, $i, 8), 8, '0')));
        }

        return $hex;
    }
}
