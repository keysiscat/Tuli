<?php

namespace Tuli;

use PHPCfg\Block;
use PHPCfg\Operand;
use PHPCfg\Op;
use Gliph\Graph\DirectedAdjacencyList;

class TypeReconstructor {

	protected $components;

	public function resolve(array $components) {
		$this->components = $components;
		$resolved = new \SplObjectStorage;
		$unresolved = new \SplObjectStorage;
		foreach ($components['variables'] as $op) {
			if (!empty($op->type) && $op->type->type !== Type::TYPE_UNKNOWN) {
				$resolved[$op] = $op->type;
			} elseif ($op instanceof Operand\Literal) {
				$resolved[$op] = $op->type = Type::fromValue($op->value);
			} else {
				$unresolved[$op] = new Type(Type::TYPE_UNKNOWN);
			}
		}

		if (count($unresolved) === 0) {
			// short-circuit
			return;
		}

		echo "Locating Type Assertions\n";
		$assertions = $this->findTypeAssertions($components['cfg']);

		$round = 1;
		do {
			echo "Round " . $round++ . " (" . count($unresolved) . " unresolved variables out of " . count($components['variables']) . ")\n";
			$start = round(count($resolved) / count($unresolved), 6);
			$i = 0;
			$toRemove = [];
			foreach ($unresolved as $k => $var) {
				$i++;
				if ($i % 10 === 0) {
					echo ".";
				}
				if ($i % 800 === 0) {
					echo "\n";
				}
				$type = $this->resolveVar($var, $resolved);
				if ($type) {
					$toRemove[] = $var;
					$resolved[$var] = $type;
				}
			}
			foreach ($toRemove as $remove) {
				$unresolved->detach($remove);
			}
			echo "\n";
		} while (count($unresolved) > 0 && $start < round(count($resolved) / count($unresolved), 6));
		foreach ($resolved as $var) {
			$var->type = $resolved[$var];
		}
		foreach ($unresolved as $var) {
			$var->type = $unresolved[$var];
		}
	}

	protected function computeMergedType(array $types) {
		if (count($types) === 1) {
			return $types[0];
		}
		$same = null;
		foreach ($types as $type) {
			if (is_null($same)) {
				$same = $type;
			} elseif ($same && !$same->equals($type)) {
				$same = false;
			}
			if ($type->type === Type::TYPE_UNKNOWN) {
				return false;
			}
		}
		if ($same) {
			return $same;
		}
		$value = 0;
		$userTypes = [];
		$subTypes = [];
		foreach ($types as $type) {
			$value |= $type->type;
			$userTypes = array_merge($userTypes, $type->userTypes);
			$subTypes = array_merge($subTypes, $type->subTypes);
		}
		return new Type($value, $userTypes, $subTypes);
	}

	protected function resolveVar(Operand $var, \SplObjectStorage $resolved) {
		$types = [];
		foreach ($var->ops as $prev) {
			$type = $this->resolveVarOp($var, $prev, $resolved);
			if ($type) {
				foreach ($type as $t) {
					$types[] = $t;
				}
			} else {
				return false;
			}
		}
		if (empty($types)) {
			return false;
		}
		return $this->computeMergedType($types);
		$same = null;
		foreach ($types as $type) {
			if (is_null($same)) {
				$same = $type;
			} elseif (!$same->equals($type)) {
				if ($this->components['typeResolver']->resolves($same, $type)) {
					// same is the supertype, switch to it
					$same = $type;
					continue;
				} elseif ($this->components['typeResolver']->resolves($type, $same)) {
					// type is a super type of same
					continue;
				}
				// Type changes with different call paths!
				return false;
			}
		}
		return $same;
	}

	protected function resolveVarOp(Operand $var, Op $op, \SplObjectStorage $resolved) {
		switch ($op->getType()) {
			case 'Expr_Array':
			case 'Expr_Cast_Array':
				// Todo: determine subtypes better
				return [new Type(Type::TYPE_ARRAY)];
			case 'Expr_ArrayDimFetch':
				if ($resolved->contains($op->var)) {
					// Todo: determine subtypes better
					$type = $resolved[$op->var];
					if ($type->subTypes) {
						return $type->subTypes;
					}
					return [new Type(Type::TYPE_MIXED)];
				}
				break;
			case 'Expr_Assign':
			case 'Expr_AssignRef':
				if ($resolved->contains($op->expr)) {
					return [$resolved[$op->expr]];
				}
				break;
			case 'Expr_BinaryOp_Equal':
			case 'Expr_BinaryOp_NotEqual':
			case 'Expr_BinaryOp_Greater':
			case 'Expr_BinaryOp_GreaterOrEqual':
			case 'Expr_BinaryOp_Identical':
			case 'Expr_BinaryOp_NotIdentical':
			case 'Expr_BinaryOp_Smaller':
			case 'Expr_BinaryOp_SmallerOrEqual':
			case 'Expr_BinaryOp_LogicalAnd':
			case 'Expr_BinaryOp_LogicalOr':
			case 'Expr_BinaryOp_LogicalXor':
			case 'Expr_BooleanNot':
			case 'Expr_Cast_Bool':
			case 'Expr_Empty':
			case 'Expr_InstanceOf':
			case 'Expr_Isset':
				return [new Type(Type::TYPE_BOOLEAN)];
			case 'Expr_BinaryOp_BitwiseAnd':
			case 'Expr_BinaryOp_BitwiseOr':
			case 'Expr_BinaryOp_BitwiseXor':
				if ($resolved->contains($op->left) && $resolved->contains($op->right)) {
					switch ([$resolved[$op->left]->type, $resolved[$op->right]->type]) {
						case [Type::TYPE_STRING, Type::TYPE_STRING]:
							return [new Type(Type::TYPE_STRING)];
						default:
							return [new Type(Type::TYPE_LONG)];
					}
				}
				break;
			case 'Expr_BitwiseNot':
				if ($resolved->contains($op->expr)) {
					if ($resolved[$op->expr]->type === Type::TYPE_STRING) {
						return [new Type(Type::TYPE_STRING)];
					}
					return [new Type(Type::TYPE_LONG)];
				}
				break;
			case 'Expr_BinaryOp_Div':
			case 'Expr_BinaryOp_Plus':
			case 'Expr_BinaryOp_Minus':
			case 'Expr_BinaryOp_Mul':
				if ($resolved->contains($op->left) && $resolved->contains($op->right)) {
					switch ([$resolved[$op->left]->type, $resolved[$op->right]->type]) {
						case [Type::TYPE_LONG, Type::TYPE_LONG]:
							return [new Type(Type::TYPE_LONG)];
						case [Type::TYPE_DOUBLE, TYPE::TYPE_LONG]:
						case [Type::TYPE_LONG, TYPE::TYPE_DOUBLE]:
						case [Type::TYPE_DOUBLE, TYPE::TYPE_DOUBLE]:
						case [Type::TYPE_MIXED, TYPE::TYPE_DOUBLE]:
						case [Type::TYPE_DOUBLE, TYPE::TYPE_MIXED]:
						case [Type::TYPE_NUMERIC, TYPE::TYPE_DOUBLE]:
						case [Type::TYPE_DOUBLE, TYPE::TYPE_NUMERIC]:
							return [new Type(Type::TYPE_DOUBLE)];
						case [Type::TYPE_MIXED, Type::TYPE_MIXED]:
						case [Type::TYPE_MIXED, Type::TYPE_LONG]:
						case [Type::TYPE_LONG, Type::TYPE_MIXED]:
						case [Type::TYPE_NUMERIC, Type::TYPE_LONG]:
						case [Type::TYPE_LONG, Type::TYPE_NUMERIC]:
						case [Type::TYPE_NUMERIC, Type::TYPE_MIXED]:
						case [Type::TYPE_MIXED, Type::TYPE_NUMERIC]:
						case [Type::TYPE_NUMERIC, Type::TYPE_NUMERIC]:
							return [new Type(Type::TYPE_NUMERIC)];
						case [Type::TYPE_ARRAY, Type::TYPE_ARRAY]:
							if ($resolved[$op->left]->subType->equals($resolved[$op->right]->subType)) {
								return [new Type(Type::TYPE_ARRAY, $resolved[$op->left]->subType)];
							}
							// TODO: handle the int->float widening case
							return [new Type(Type::TYPE_ARRAY, new Type(Type::TYPE_MIXED))];
						default:
							throw new \RuntimeException("Unknown Type Pair: " . $resolved[$op->left] . ":" . $resolved[$op->right]);
					}
				}

				break;
			case 'Expr_BinaryOp_Concat':
			case 'Expr_Cast_String':
			case 'Expr_ConcatList':
				return [new Type(Type::TYPE_STRING)];
			case 'Expr_BinaryOp_Mod':
			case 'Expr_BinaryOp_ShiftLeft':
			case 'Expr_BinaryOp_ShiftRight':
			case 'Expr_Cast_Int':
			case 'Expr_Print':
				return [new Type(Type::TYPE_LONG)];
			case 'Expr_Cast_Double':
				return [new Type(Type::TYPE_DOUBLE)];
			case 'Expr_Cast_Object':
				if ($resolved->contains($op->expr)) {
					if ($resolved[$op->expr]->type === Type::TYPE_USER) {
						return [$resolved[$op->expr]];
					}
					return [new Type(Type::TYPE_USER, null, 'stdClass')];
				}
				break;
			case 'Expr_Clone':
				if ($resolved->contains($op->expr)) {
					return [$resolved[$op->expr]];
				}
				break;
			case 'Expr_Closure':
				return [new Type(Type::TYPE_USER, null, "Closure")];
			case 'Expr_FuncCall':
				if ($op->name instanceof Operand\Literal) {
					$name = strtolower($op->name->value);
					if (isset($this->components['functionLookup'][$name])) {
						$result = [];
						foreach ($this->components['functionLookup'][$name] as $func) {
							if ($func->returnType) {
								$result[] = Type::fromDecl($func->returnType->value);
							} else {
								// Check doc comment
								$result[] = Type::extractTypeFromComment("return", $func->getAttribute('doccomment'));
							}
						}
						return $result;
					}
				}
				// we can't resolve the function
				return [new Type(Type::TYPE_MIXED)];
				break;
			case 'Expr_List':
				if ($op->result === $var) {
					return [new Type(Type::TYPE_ARRAY, new Type(Type::TYPE_MIXED))];
				}
				// TODO: infer this
				return [new Type(Type::TYPE_MIXED)];
			case 'Expr_New':
				if ($op->class instanceof Operand\Literal) {
					return [new Type(Type::TYPE_USER, [], [$op->class->value])];
				}
				return [new Type(Type::TYPE_OBJECT)];
			case 'Expr_Param':
				if ($op->type) {
					$type = Type::fromDecl($op->type->value);
					if ($op->defaultVar) {
						if ($op->defaultBlock->children[0]->getType() === "Expr_ConstFetch" && strtolower($op->defaultBlock->children[0]->name->value) === "null") {
							$type->type |= Type::TYPE_NULL;
						}
					}
					return [$type];
				}
				return [Type::extractTypeFromComment("param", $op->function->getAttribute('doccomment'), $op->name->value)];
			case 'Expr_Yield':
			case 'Expr_Include':
			case 'Expr_PropertyFetch':
			case 'Expr_StaticPropertyFetch':
			case 'Stmt_Property':
				// TODO: we may be able to determine these...
				return [new Type(Type::TYPE_MIXED)];
			case 'Expr_TypeAssert':
				return [Type::fromDecl($op->assertedType)];
			case 'Expr_TypeUnAssert':
				if ($resolved->contains($op->assert->expr)) {
					return [$resolved[$op->assert->expr]];
				}
			case 'Expr_UnaryMinus':
			case 'Expr_UnaryPlus':
				if ($resolved->contains($op->expr)) {
					switch ($resolved[$op->expr]->type) {
						case Type::TYPE_LONG:
						case Type::TYPE_DOUBLE:
							return [$resolved[$op->expr]];
					}
					return [new Type(Type::TYPE_NUMERIC)];
				}
				break;

			case 'Expr_Eval':
				return [new Type(Type::TYPE_MIXED)];
			case 'Iterator_Key':
				if ($resolved->contains($op->var)) {
					// TODO: implement this as well
					return [new Type(Type::TYPE_MIXED)];
				}
				break;
			case 'Expr_Exit':
			case 'Iterator_Reset':
				return [new Type(Type::TYPE_VOID)];
			case 'Iterator_Valid':
				return [new Type(Type::TYPE_BOOLEAN)];
			case 'Iterator_Value':
				if ($resolved->contains($op->var)) {
					if ($resolved[$op->var]->subTypes) {
						return $resolved[$op->var]->subTypes;
					}
					return [new Type(Type::TYPE_MIXED)];
				}
				break;
			case 'Expr_MethodCall':
				if (!$op->name instanceof Operand\Literal) {
					// variable method call
					return [new Type(Type::TYPE_MIXED)];
				}
				if ($resolved->contains($op->var)) {
					if ($resolved[$op->var]->type !== Type::TYPE_USER) {
						return [new Type(Type::TYPE_MIXED)];
					}
					$types = [];
					foreach ($resolved[$op->var]->userTypes as $ut) {
						$className = strtolower($ut);
						if (!isset($this->components['resolves'][$className])) {
							return [new Type(Type::TYPE_MIXED)];
						}
						foreach ($this->components['resolves'][$className] as $class) {
							$method = $this->findMethod($class, $op->name->value);
							if (!$method) {
								continue;
							}
							if (!$method->returnType) {
								$types[] = Type::extractTypeFromComment("return", $method->getAttribute('doccomment'));
							} else {
								$types[] = Type::fromDecl($method->returnType->value);
							}
						}
					}
					return $types;
				}
				break;
			case 'Expr_ConstFetch':
			case 'Expr_StaticCall':
			case 'Expr_ClassConstFetch':
				//TODO
				return [new Type(Type::TYPE_MIXED)];
			case 'Phi':
				$types = [];
				foreach ($op->vars as $var) {
					if ($resolved->contains($var)) {
						$types[] = $resolved[$var];
					} else {
						// entire phi isn't resolved yet, can't process
						continue 2;
					}
				}
				$type = $this->computeMergedType($types);
				if ($type) {
					return [$type];
				}
				return false;
			default:
				throw new \RuntimeException("Unknown operand prefix type: " . $op->getType());
		}
		return false;
	}

	protected function findMethod($class, $name) {
		foreach ($class->stmts->children as $stmt) {
			if ($stmt instanceof Op\Stmt\ClassMethod) {
				if (strtolower($stmt->name->value) === $name) {
					return $stmt;
				}
			}
		}
		if ($name !== '__call') {
			return $this->findMethod($class, '__call');
		}
		return null;
	}

	protected function findTypeAssertions(array $blocks) {
		$toProcess = new \SplObjectStorage;
		$processed = new \SplObjectStorage;
		foreach ($blocks as $block) {
			$toProcess->attach($block);
		}
		$assertions = [];
		while ($toProcess->count()) {
			foreach ($toProcess as $block) {
				$toProcess->detach($block);
				$processed->attach($block);
				foreach ($block->children as $op) {
					switch ($op->getType()) {
						case 'Expr_InstanceOf':
							if ($op->class instanceof Operand\Literal) {
								$assertions[] = [
									new Type(Type::TYPE_USER, [], [$op->class->value]),
									$op->expr,
									$op->result,	
								];
							}
							break;
						case 'Expr_FuncCall':
							if ($op->name instanceof Operand\Literal) {
								$assertionFunctions = [
									'is_array' 	  => Type::TYPE_ARRAY,
									'is_bool' 	  => Type::TYPE_BOOLEAN,
									'is_callable' => Type::TYPE_CALLABLE,
									'is_double'	  => Type::TYPE_DOUBLE,
									'is_float'	  => Type::TYPE_DOUBLE,
									'is_int'	  => Type::TYPE_LONG,
									'is_integer'  => Type::TYPE_LONG,
									'is_long'	  => Type::TYPE_LONG,
									'is_null'	  => Type::TYPE_NULL,
									'is_numeric'  => Type::TYPE_NUMERIC,
									'is_object'   => Type::TYPE_OBJECT,
									'is_real'	  => Type::TYPE_DOUBLE,
									'is_string'   => Type::TYPE_STRING,
								];
								$name = strtolower($op->name->value);
								if (isset($assertionFunctions[$name])) {
									$assertions[] = [
										new Type($assertionFunctions[$name]),
										$op->args[0],
										$op->result,
									];
								}
							}
					}
					foreach ($op->getSubBlocks() as $name) {
						$sub = $op->$name;
						if (is_null($sub)) {
							continue;
						}
						if (!is_array($sub)) {
							$sub = [$sub];
						}
						foreach ($sub as $subblock) {
							if (!$processed->contains($subblock)) {
								$toProcess->attach($subblock);
							}
						}
					}
				}
			}
		}
		//var_dump($assertions);
		return $assertions;
	}

}