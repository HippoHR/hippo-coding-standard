<?php
/**
 * Hippo_Sniffs_ControlStructures_SwitchDeclarationSniff.
 *
 * PHP version 5
 *
 * @category PHP
 * @package  PHP_CodeSniffer
 * @author   Dennis Broeks <dennis@uitzendbureau.nl>
 * @license  https://github.com/squizlabs/PHP_CodeSniffer/blob/master/licence.txt BSD Licence
 * @link     https://github.com/HippoHR/phpcs-coding-standard
 */
namespace Hippo\Sniffs\ControlStructures;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * Hippo_Sniffs_ControlStructures_SwitchDeclarationSniff.
 *
 * Ensures all switch statements are defined correctly.
 *
 * This is a customized version of
 *  PSR2/Sniffs/ControlStructures/SwitchDeclarationSniff.php
 * Changes:
 * - indentation of 2 spaces instead of 4.
 *
 * @category PHP
 * @package  PHP_CodeSniffer
 * @author   Dennis Broeks <dennis@uitzendbureau.nl>
 * @license  https://github.com/squizlabs/PHP_CodeSniffer/blob/master/licence.txt BSD Licence
 * @link     https://github.com/HippoHR/phpcs-coding-standard
 */

class SwitchDeclarationSniff implements Sniff
{


    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array
     */
    public function register()
    {
        return [T_SWITCH];

    }//end register()


    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int  $stackPtr  The position of the current token in the
     *                        stack passed in $tokens.
     *
     * @return void
     */
    public function process(File $phpcsFile, $stackPtr)
    {
        $tokens = $phpcsFile->getTokens();

        // We can't process SWITCH statements unless we know where they start and end.
        if (isset($tokens[$stackPtr]['scope_opener']) === false
            || isset($tokens[$stackPtr]['scope_closer']) === false
        ) {
            return;
        }

        $switch        = $tokens[$stackPtr];
        $nextCase      = $stackPtr;
        $caseAlignment = ($switch['column'] + 2);
        $caseCount     = 0;
        $foundDefault  = false;

        while (($nextCase = $this->findNextCase($phpcsFile, ($nextCase + 1), $switch['scope_closer'])) !== false) {
            if ($tokens[$nextCase]['code'] === T_DEFAULT) {
                $type         = 'default';
                $foundDefault = true;
            } else {
                $type = 'case';
                $caseCount++;
            }

            if ($tokens[$nextCase]['content'] !== strtolower($tokens[$nextCase]['content'])) {
                $expected = strtolower($tokens[$nextCase]['content']);
                $error    = strtoupper($type).' keyword must be lowercase; expected "%s" but found "%s"';
                $data     = [
                    $expected,
                    $tokens[$nextCase]['content'],
                ];
                $phpcsFile->addError($error, $nextCase, $type.'NotLower', $data);
            }

            if ($tokens[$nextCase]['column'] !== $caseAlignment) {
                $error = strtoupper($type).' keyword must be indented 2 spaces from SWITCH keyword';
                $phpcsFile->addError($error, $nextCase, $type.'Indent');
            }

            $prevCode   = $phpcsFile->findPrevious(T_WHITESPACE, ($nextCase - 1), $stackPtr, true);
            $blankLines = ($tokens[$nextCase]['line'] - $tokens[$prevCode]['line'] - 1);
            if ($blankLines !== 0) {
                $error = 'Blank lines are not allowed between case statements; found %s';
                $data  = [$blankLines];
                $phpcsFile->addError($error, $nextCase, 'SpaceBetweenCase', $data);
            }

            if ($type === 'case'
                && ($tokens[($nextCase + 1)]['code'] !== T_WHITESPACE
                || $tokens[($nextCase + 1)]['content'] !== ' ')
            ) {
                $error = 'CASE keyword must be followed by a single space';
                $phpcsFile->addError($error, $nextCase, 'SpacingAfterCase');
            }

            $opener = $tokens[$nextCase]['scope_opener'];
            if ($tokens[$opener]['code'] === T_COLON) {
                if ($tokens[($opener - 1)]['code'] === T_WHITESPACE) {
                    $error = 'There must be no space before the colon in a '.strtoupper($type).' statement';
                    $phpcsFile->addError($error, $nextCase, 'SpaceBeforeColon'.$type);
                }
            } else {
                $error = '%s statements must be defined using a colon';
                $data  = [strtoupper($type)];
                $phpcsFile->addError($error, $nextCase, 'WrongOpener'.$type, $data);
            }

            $nextCloser = $tokens[$nextCase]['scope_closer'];
            if ($tokens[$nextCloser]['scope_condition'] === $nextCase) {
                // Only need to check some things once, even if the
                // closer is shared between multiple case statements, or even
                // the default case.
                if ($tokens[$nextCloser]['column'] !== ($caseAlignment + 2)) {
                    $error = 'Terminating statement must be indented to the same level as the CASE body';
                    $phpcsFile->addError($error, $nextCloser, 'BreakIndent');
                }
            }

            // We only want cases from here on in.
            if ($type !== 'case') {
                continue;
            }

            $nextCode = $phpcsFile->findNext(
                T_WHITESPACE,
                ($tokens[$nextCase]['scope_opener'] + 1),
                $nextCloser,
                true
            );

            if ($tokens[$nextCode]['code'] !== T_CASE && $tokens[$nextCode]['code'] !== T_DEFAULT) {
                // This case statement has content. If the next case or default comes
                // before the closer, it means we dont have a terminating statement
                // and instead need a comment.
                $nextCode = $this->findNextCase($phpcsFile, ($tokens[$nextCase]['scope_opener'] + 1), $nextCloser);
                if ($nextCode !== false) {
                    $prevCode = $phpcsFile->findPrevious(T_WHITESPACE, ($nextCode - 1), $nextCase, true);
                    if ($tokens[$prevCode]['code'] !== T_COMMENT) {
                        $error = 'There must be a comment when fall-through is intentional in a non-empty case body';
                        $phpcsFile->addError($error, $nextCase, 'TerminatingComment');
                    }
                }
            }
        }//end while

    }//end process()


    /**
     * Find the next CASE or DEFAULT statement from a point in the file.
     *
     * Note that nested switches are ignored.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int  $stackPtr  The position to start looking at.
     * @param int  $end       The position to stop looking at.
     *
     * @return int | bool
     */
    private function findNextCase(File $phpcsFile, $stackPtr, $end)
    {
        $tokens = $phpcsFile->getTokens();
        while (($stackPtr = $phpcsFile->findNext([T_CASE, T_DEFAULT, T_SWITCH], $stackPtr, $end)) !== false) {
            // Skip nested SWITCH statements; they are handled on their own.
            if ($tokens[$stackPtr]['code'] === T_SWITCH) {
                $stackPtr = $tokens[$stackPtr]['scope_closer'];
                continue;
            }

            break;
        }

        return $stackPtr;

    }//end findNextCase()


}//end class
